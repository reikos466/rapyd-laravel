<?php namespace Zofe\Rapyd\DataGrid;

use Illuminate\Support\Facades\View;
use Zofe\Rapyd\DataSet as DataSet;
use Zofe\Rapyd\Persistence;
use Illuminate\Support\Facades\Config;
use App\Emisore;
use App\Factura;
use ZipArchive;

use Redirect;


class DataGrid extends DataSet
{

    protected $fields = array();
    /** @var Column[]  */
    public $columns = array();
    public $headers = array();
    public $rows = array();
    public $output = "";
    public $attributes = array("class" => "table");
    public $checkbox_form = false;
    
    protected $row_callable = array();

    /**
     * @param string $name
     * @param string $label
     * @param bool   $orderby
     *
     * @return Column
     */
    public function add($name, $label = null, $orderby = false)
    {
        $column = new Column($name, $label, $orderby);
        $this->columns[$column->name] = $column;
        if (!in_array($name,array("_edit"))) {
            $this->headers[] = $label;
        }
        if ($orderby) {
            $this->addOrderBy($column->orderby_field);
        }
        return $column;
    }

    //todo: like "field" for DataForm, should be nice to work with "cell" as instance and "row" as collection of cells
    public function build($view = '')
    {
        ($view == '') and $view = 'rapyd::datagrid';
        parent::build();

        Persistence::save();

        foreach ($this->data as $tablerow) {

            $row = new Row($tablerow);

            foreach ($this->columns as $column) {

                $cell = new Cell($column->name);
                $sanitize = (count($column->filters) || $column->cell_callable) ? false : true;
                $value = $this->getCellValue($column, $tablerow, $sanitize);
                $cell->value($value);
                $cell->parseFilters($column->filters);
                if ($column->cell_callable) {
                    $callable = $column->cell_callable;
                    $cell->value($callable($cell->value, $tablerow));
                }
                $row->add($cell);
            }

            if (count($this->row_callable)) {
                foreach ($this->row_callable as $callable) {
                    $callable($row);
                }
            }
            $this->rows[] = $row;
        }

        return \View::make($view, array('dg' => $this, 'buttons'=>$this->button_container, 'label'=>$this->label));
    }

    public function buildCSV($file = '', $timestamp = '', $sanitize = true, $del = array())
    {
        $this->limit = null;
        parent::build();
		$segments = \Request::segments();

        $filename = ($file != '') ? basename($file, '.csv') : end($segments);
        $filename = preg_replace('/[^0-9a-z\._-]/i', '',$filename);
        $filename .= ($timestamp != "") ? date($timestamp).".csv" : ".csv";
		
		
        $save = (bool) strpos($file,"/");

        //Delimiter
        $delimiter = array();
        $delimiter['delimiter'] = isset($del['delimiter']) ? $del['delimiter'] : ',';
        $delimiter['enclosure'] = isset($del['enclosure']) ? $del['enclosure'] : '"';
        $delimiter['line_ending'] = isset($del['line_ending']) ? $del['line_ending'] : "\n";

        if ($save) {
            $handle = fopen(public_path().'/'.dirname($file)."/".$filename, 'w');

        } else {

            $headers  = array(
                'Content-Type' => 'text/csv',
                'Pragma'=>'no-cache',
                //'"Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
                'Content-Disposition' => 'attachment; filename="' . $filename.'"');

            $handle = fopen('php://output', 'w');
            ob_start();
        }

        fputs($handle, $delimiter['enclosure'].implode($delimiter['enclosure'].$delimiter['delimiter'].$delimiter['enclosure'], $this->headers) .$delimiter['enclosure'].$delimiter['line_ending']);

        foreach ($this->data as $tablerow) {
            $row = new Row($tablerow);
			
			$row2 = $tablerow->toArray();
			
            foreach ($this->columns as $column) {

                if (in_array($column->name,array("_edit")))
                    continue;

                $cell = new Cell($column->name);
                $value =  str_replace('"', '""',str_replace(PHP_EOL, '', strip_tags($this->getCellValue($column, $tablerow, $sanitize))));
                
                if($value == 'misaldomn') {
	                $emisor = Emisore::where('id',$row2['id'])->first();
	                $saldo = $emisor->saldo()['MXN'];
	                $cell->value( $saldo );
	                
                } else if($value == 'misaldome') {
	                $emisor = Emisore::where('id',$row2['id'])->first();
	                $saldo = $emisor->saldo()['USD'];
	                $cell->value( $saldo );
                } 
                
                if($value == 'vence') {
	                $factura = Factura::where('id',$row2['id'])->first();
	                
	                $proveedor = Emisore::where('emisor',$factura->emisor)->where('user_id',$factura->user_id)->first();
					
					if($proveedor!=NULL){
						$vencimiento = round($factura->vence(),0); //$fecha->format('d-m-Y');
					} 
					else $vencimiento = "?";

	                $cell->value( $vencimiento );
	            }
                else {
				    $cell->value($value);
                }
                	
                
                $row->add($cell );
            }
			
			
            if (count($this->row_callable)) {
                foreach ($this->row_callable as $callable) {
                    $callable($row);
                }
            }
			
			// FILTROS

			$problem = false;
			
			
			if(isset($_GET['fechaEmision'])){
				$date = new \DateTime($row2['fechaEmision']); 				
				if($_GET['fechaEmision']['from']!=""){
					$from = \DateTime::createFromFormat('d/m/Y H:i:s',$_GET['fechaEmision']['from']."00:00:00");
					if($date < $from) $problem = true;
				}
				if($_GET['fechaEmision']['to']!=""){
					$to = \DateTime::createFromFormat('d/m/Y H:i:s',$_GET['fechaEmision']['to']."23:59:59");
					if($date > $to) $problem = true;
				}
			}
			
			if(isset($_GET['created_at'])){
				$created = new \DateTime($row2['created_at']); 				
				if($_GET['created_at']['from']!=""){
					$from = \DateTime::createFromFormat('d/m/Y H:i:s',$_GET['created_at']['from']."00:00:00");
					if($created < $from) $problem = true;
				}
				if($_GET['created_at']['to']!=""){
					$to = \DateTime::createFromFormat('d/m/Y H:i:s',$_GET['created_at']['to']."23:59:59");
					if($created > $to) $problem = true;
				}
			}
				
			if(isset($_GET['status_comer']) && $_GET['status_comer'] != "" && $row2['status_comer'] != $_GET['status_comer']) $problem = true;	
			if(isset($_GET['emisor']) && $_GET['emisor'] != "" && $row2['emisor'] != $_GET['emisor'])  $problem = true;		
			if(isset($_GET['receptor']) && $_GET['receptor'] != "" && $row2['receptor'] != $_GET['receptor'] )  $problem = true;			
			
			if(isset($_GET['extraSelect1']) && $_GET['extraSelect1'] != "" && $row2['extraSelect1'] != $_GET['extraSelect1'] )  $problem = true;		
			if(isset($_GET['extraSelect2']) && $_GET['extraSelect2'] != "" && $row2['extraSelect2'] != $_GET['extraSelect2'] )  $problem = true;		

			if(isset($_GET['exportMN']) && $_GET['exportMN'] != "" && $row['misaldomn'] < 1) $problem = true;	
			if(isset($_GET['exportME']) && $_GET['exportME'] != "" && $row['misaldome'] < 1) $problem = true;	
						
			// FIN DE LOS FILTROS

			
			if($problem) {
				//	
			}
			else {
			    fputs($handle, $delimiter['enclosure'] . implode($delimiter['enclosure'].$delimiter['delimiter'].$delimiter['enclosure'], $row->toArray()) . $delimiter['enclosure'].$delimiter['line_ending']);
			}
        }

        fclose($handle);
        
        if ($save) {
            //redirect, boolean or filename?
        } else {
            $output = ob_get_clean();

            return \Response::make(rtrim($output, "\n"), 200, $headers);
        }
    }
	
	
	//-----------------
	
	
	public function buildLayout($file = '', $timestamp = '', $sanitize = true, $del = array())
    {
        $this->limit = null;
        parent::build();
		$segments = \Request::segments();

        $filename = ($file != '') ? basename($file, '.txt') : end($segments);
        $filename = preg_replace('/[^0-9a-z\._-]/i', '',$filename);
        $filename .= ($timestamp != "") ? date($timestamp).".txt" : ".txt";
		
		
        $save = (bool) strpos($file,"/");

        //Delimiter
        $delimiter = array();
        $delimiter['delimiter'] = isset($del['delimiter']) ? $del['delimiter'] : '';
        $delimiter['enclosure'] = isset($del['enclosure']) ? $del['enclosure'] : '';
        $delimiter['line_ending'] = isset($del['line_ending']) ? $del['line_ending'] : "\r\n";

        if ($save) {
            $handle = fopen(public_path().'/'.dirname($file)."/".$filename, 'w');

        } else {

            $headers  = array(
                'Content-Type' => 'text/txt',
                'Pragma'=>'no-cache',
                //'"Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
                'Content-Disposition' => 'attachment; filename="' . $filename.'"');

            $handle = fopen('php://output', 'w');
            ob_start();
        }

        //fputs($handle, $delimiter['enclosure'].implode($delimiter['enclosure'].$delimiter['delimiter'].$delimiter['enclosure'], $this->headers) .$delimiter['enclosure'].$delimiter['line_ending']);

        foreach ($this->data as $tablerow) {
            $row = new Row($tablerow);
			
			$row2 = $tablerow->toArray();
			
            foreach ($this->columns as $column) {

                if (in_array($column->name,array("_edit")))
                    continue;

                $cell = new Cell($column->name);
                $value =  str_replace('"', '""',str_replace(PHP_EOL, '', strip_tags($this->getCellValue($column, $tablerow, $sanitize))));
                
                if($value == 'misaldomn') {
	                $emisor = Emisore::where('id',$row2['id'])->first();
	                $saldo = $emisor->saldo()['MXN'];
	                $cell->value( sprintf('%016.2f', $saldo,$saldo) );
	                
                } else if($value == 'misaldome') {
	                $emisor = Emisore::where('id',$row2['id'])->first();
	                $saldo = $emisor->saldo()['USD'];
	                $cell->value( $saldo );
                }
                else if($value == '4carcue') {
	                $emisor = Emisore::where('id',$row2['id'])->first();
	                $cell->value( substr($emisor->clabe, 0,3) );
                }
                else if($value == 'beneficiario') {
	                $emisor = Emisore::where('id',$row2['id'])->first();
	                $cell->value(  sprintf("%-30s", substr(strip_tags($emisor->emisorNombre), 0, 30)) );
                }
                else if($value == 'cuentaBancomer') {
	                $emisor = Emisore::where('id',$row2['id'])->first();
	                $cell->value(  sprintf("%018d", $emisor->cuenta) );
                }
                
                
                else {
				    $cell->value($value);
                }
                	
                
                $row->add($cell );
            }
			
			
            if (count($this->row_callable)) {
                foreach ($this->row_callable as $callable) {
                    $callable($row);
                }
            }
			
			// FILTROS

			$problem = false;
			
			if(isset($_GET['exportMN']) && $_GET['exportMN'] == "" && $row2['banco'] == "BANCOMER") $problem = true;	
			if(isset($_GET['exportME']) && $_GET['exportME'] == "" && $row2['banco'] != 'BANCOMER') $problem = true;	

			if(isset($_GET['exportMN']) && $_GET['exportMN'] == "" && $emisor->saldo()['MXN'] == 0 ) $problem = true;	
			if(isset($_GET['exportME']) && $_GET['exportME'] == "" && $emisor->saldo()['MXN'] == 0 ) $problem = true;	
						
			// FIN DE LOS FILTROS

			if($problem) {
				//	
			}
			else {
			    fputs($handle, $delimiter['enclosure'] . implode($delimiter['enclosure'].$delimiter['delimiter'].$delimiter['enclosure'], $row->toArray()) . $delimiter['enclosure'].$delimiter['line_ending']);
			}
        }

        fclose($handle);
        
        if ($save) {
            //redirect, boolean or filename?
        } else {
            $output = ob_get_clean();

            return \Response::make(rtrim($output, "\n"), 200, $headers);
        }
    }
	
	
	//---------------
	
	
	public function buildZIP($file = '', $empresa = '',$tipo='', $sanitize = true, $del = array())
    {
		
		
        $this->limit = null;
        parent::build();
		$segments = \Request::segments();

        $filename = ($file != '') ? basename($file, '.zip') : end($segments);
        $filename = preg_replace('/[^0-9a-z\._-]/i', '',$filename);
        $filename .= ".zip";

        $zip = new ZipArchive();		
		$headers = array('Content-Type' => 'application/octet-stream');
        
        $zip->open(public_path() . '/uploads/' . $empresa . '/' . $filename, ZipArchive::OVERWRITE);
	    
	    foreach ($this->data as $tablerow) {
    	    
    	    $row = $tablerow->toArray();
    	    //return $row;	
    	    $row2 = $tablerow->toArray();
    	    
			$file = public_path() . '/uploads/'. $empresa .'/'. $row['uuid'];
			
			// FILTROS

			$problem = false;

			if(isset($_GET['fechaEmision'])){
				$date = new \DateTime($row2['fechaEmision']); 				
				if($_GET['fechaEmision']['from']!=""){
					$from = \DateTime::createFromFormat('d/m/Y H:i:s',$_GET['fechaEmision']['from']."00:00:00");
					if($date < $from) $problem = true;
				}
				if($_GET['fechaEmision']['to']!=""){
					$to = \DateTime::createFromFormat('d/m/Y H:i:s',$_GET['fechaEmision']['to']."23:59:59");
					if($date > $to) $problem = true;
				}
			}
			
			if(isset($_GET['created_at'])){
				$created = new \DateTime($row2['created_at']); 				
				if($_GET['created_at']['from']!=""){
					$from = \DateTime::createFromFormat('d/m/Y H:i:s',$_GET['created_at']['from']."00:00:00");
					if($created < $from) $problem = true;
				}
				if($_GET['created_at']['to']!=""){
					$to = \DateTime::createFromFormat('d/m/Y H:i:s',$_GET['created_at']['to']."23:59:59");
					if($created > $to) $problem = true;
				}
			}
				
			if(isset($_GET['status_comer']) && $_GET['status_comer'] != "" && $row2['status_comer'] != $_GET['status_comer']) $problem = true;	
			if(isset($_GET['emisor']) && $_GET['emisor'] != "" && $row2['emisor'] != $_GET['emisor'])  $problem = true;		
			if(isset($_GET['receptor']) && $_GET['receptor'] != "" && $row2['receptor'] != $_GET['receptor'] )  $problem = true;			
			
			if(isset($_GET['extraSelect1']) && $_GET['extraSelect1'] != "" && $row2['extraSelect1'] != $_GET['extraSelect1'] )  $problem = true;		
			if(isset($_GET['extraSelect2']) && $_GET['extraSelect2'] != "" && $row2['extraSelect2'] != $_GET['extraSelect2'] )  $problem = true;		
			
			// FIN DE LOS FILTROS
			
			if($problem) {
				//	
			}
			else {
				if($tipo == 'xml'){
					$zip->addFile($file . '.xml', $row['uuid']. '.xml');
				}
				else if($tipo == 'pdf'){
					if($row['pdf']!=""){
						$zip->addFile($file . '.pdf', $row['uuid']. '.pdf');	
					}
				}
				else if($tipo == 'pdf2'){
					if($row['pdf']!=""){
						$zip->addFile($file . '-2.pdf', $row['uuid']. '-2.pdf');	
					}
				}
				else if($tipo == 'all'){
					$zip->addFile($file . '.xml', $row['uuid']. '.xml');
					if($row['pdf']!=""){
						$zip->addFile($file . '.pdf', $row['uuid']. '.pdf');	
					}
				}
			}
			
			
			
		}
        
        $zip->close();
        
		return \Response::download(public_path().'/uploads/'.$empresa .'/'.$filename, $filename, $headers); 
    }
	
	
	//////////////////
	
	public function markAllPagado($file = '', $timestamp = '', $sanitize = true, $del = array())
    {
        $this->limit = null;
        parent::build();
		$segments = \Request::segments();

      
        foreach ($this->data as $tablerow) {
            $row = new Row($tablerow);
			
			$row2 = $tablerow->toArray();
			
			$emisor = Emisore::where('id',$row2['id'])->first();
	        $emisor->markAllPagado();
	        
        }

        return Redirect::back()->withInput()->with('message',  "Todas las facturas vencidas aprobadas se han marcado como pagadas");
    }
    
	
	
	
	//////////////////
	
	
	public function buildZIPPDF($file = '', $empresa = '', $sanitize = true, $del = array())
    {
        $this->limit = null;
        parent::build();
		$segments = \Request::segments();

        $filename = ($file != '') ? basename($file, '.zip') : end($segments);
        $filename = preg_replace('/[^0-9a-z\._-]/i', '',$filename);
        $filename .= ".zip";

        $zip = new ZipArchive();		
		$headers = array('Content-Type' => 'application/octet-stream');
        
        $zip->open(public_path() . '/uploads/' . $empresa . '/' . $filename, ZipArchive::OVERWRITE);
	    
	    foreach ($this->data as $tablerow) {
    	    
    	    $row = $tablerow->toArray();
    	    //return $row;	
    	    
			$file = public_path() . '/uploads/'. $empresa .'/'. $row['uuid'];
			
			if($row['pdf']!=""){
				$zip->addFile($file . '.pdf', $row['uuid']. '.pdf');	
			}
        }
        
        $zip->close();
		return \Response::download(public_path().'/uploads/'.$empresa .'/'.$filename, $filename, $headers); 
    }
	
	//---------------
	
    protected function getCellValue($column, $tablerow, $sanitize = true)
    {
        //blade
        if (strpos($column->name, '{{') !== false || 
            strpos($column->name, '{!!') !== false) {

            if (is_object($tablerow) && method_exists($tablerow, "getAttributes")) {
                $fields = $tablerow->getAttributes();
                $relations = $tablerow->getRelations();
                $array = array_merge($fields, $relations) ;

                $array['row'] = $tablerow;

            } else {
                $array = (array) $tablerow;
            }

            $value = $this->parser->compileString($column->name, $array);

        //eager loading smart syntax  relation.field
        } elseif (preg_match('#^[a-z0-9_-]+(?:\.[a-z0-9_-]+)+$#i',$column->name, $matches) && is_object($tablerow) ) {
            //switch to blade and god bless eloquent
            $_relation = '$'.trim(str_replace('.','->', $column->name));
            $expression = '{{ isset('. $_relation .') ? ' . $_relation . ' : "" }}';
            $fields = $tablerow->getAttributes();
            $relations = $tablerow->getRelations();
            $array = array_merge($fields, $relations) ;
            $value = $this->parser->compileString($expression, $array);

        //fieldname in a collection
        } elseif (is_object($tablerow)) {

            $value = @$tablerow->{$column->name};
            if ($sanitize) {
                $value = $this->sanitize($value);
            }
        //fieldname in an array
        } elseif (is_array($tablerow) && isset($tablerow[$column->name])) {

            $value = $tablerow[$column->name];

        //none found, cell will have the column name
        } else {
            $value = $column->name;
        }

        //decorators, should be moved in another method
        if ($column->link) {
            if (is_object($tablerow) && method_exists($tablerow, "getAttributes")) {
                $array = $tablerow->getAttributes();
                $array['row'] = $tablerow;
            } else {
                $array = (array) $tablerow;
            }
            $value =  '<a href="'.$this->parser->compileString($column->link, $array).'">'.$value.'</a>';
        }
        if (count($column->actions)>0) {
            $key = ($column->key != '') ?  $column->key : $this->key;
            $keyvalue = @$tablerow->{$key};

            $value = \View::make('rapyd::datagrid.actions', array('uri' => $column->uri, 'id' => $keyvalue, 'actions' => $column->actions));

        }

        return $value;
    }

    public function getGrid($view = '')
    {
        $this->output = $this->build($view)->render();

        return $this->output;
    }

    public function __toString()
    {
        if ($this->output == "") {

           //to avoid the error "toString() must not throw an exception"
           //http://stackoverflow.com/questions/2429642/why-its-impossible-to-throw-exception-from-tostring/27307132#27307132
           try {
               $this->getGrid();
           }
           catch (\Exception $e) {
               $previousHandler = set_exception_handler(function (){ });
               restore_error_handler();
               call_user_func($previousHandler, $e);
               die;
           }

        }

        return $this->output;
    }

    public function edit($uri, $label='Edit', $actions='show|modify|delete', $key = '')
    {
        return $this->add('_edit', $label)->actions($uri, explode('|', $actions))->key($key);
    }

    public function getColumn($column_name)
    {
        if (isset($this->columns[$column_name])) {
            return $this->columns[$column_name];
        }
    }

    public function addActions($uri, $label='Edit', $actions='show|modify|delete', $key = '')
    {
        return $this->edit($uri, $label, $actions, $key);
    }

    public function row(\Closure $callable)
    {
        $this->row_callable[] = $callable;

        return $this;
    }

    protected function sanitize($string)
    {
        $result = nl2br(htmlspecialchars($string));
        return Config::get('rapyd.sanitize.num_characters') > 0 ? str_limit($result, Config::get('rapyd.sanitize.num_characters')) : $result;
    }

}
