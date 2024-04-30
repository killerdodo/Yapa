<?php

class Yapa{
	
	public $unique_id;
	
	public $url;
	private $table;
	public $col_en = [];
	public $col_ch = [];
	private $join = [];
	public $show = [];
	public $type = [];
	private $auth = [];
	private $database;
	private $data;
	private $tree;
	private $config;
	
	public $col_num;
	private $uid;
	
	public $arg;
	public $act;
	
	private $tpl;
	
	public function __construct($url, $table, $col_en, $col_ch, $join, $show, $type, $auth, $medoo, $config = []){
		
		$this->unique_id = 'form' . uniqid();
		
		$this->url = $url;
		$this->table = $table;
		$this->table_o = $table;
		$this->id = $config['id'] ?? $col_en[0];
		$this->ids = $this->split($this->id);
		$this->concat_id = '`' . $this->id . '`';
		$this->database = $medoo;
		
		if($this->count($this->ids) > 1){
			$this->concat_id = 'CONCAT(`' . implode("`, '_', `", $this->ids) . '`)';
			$this->id = implode('_', $this->ids);
			
			$col_en[] = $this->id;
			$col_ch[] = $this->id;
			$join[] = '';
			$show[] = 'hidden';
			$type[] = 'hidden';
			// combine id as new view
			$sql = 'SELECT *, ' . $this->concat_id . ' as `' . $this->id . '` FROM `' . $table . '`';
			$table = 'v_tmp_' . md5($sql);
			$config['table'][$table] = $sql;
			$this->table = $table;
		}
		
		// separate label and info
		$label = [];
		foreach($col_ch as $v){
			$tmp = $this->split($v, 'label');
			$label[0][] = $tmp[0] ?? '';
			$label[1][] = $tmp[1] ?? '';
		}
		
		// join
		$chain = [];
		$tree = ['col' => null];
		foreach($join as $k=>$v){
			$chain[] = $v? $this->split($v, 'chain'): '';
			// tree view check
			if($config['tree'] ?? 0){
				$tree['col'] = $config['tree'];
			}else{
				if(($chain[$k][0] ?? '') == $this->table){
					$tree['col'] = $k;
				}
			}
		}
		
		// type with attr
		$attr = [];
		foreach($type as $k=>$v){
			$tmp = $v? $this->split($v, 'label'): '';
			$attr[0][] = $tmp[0] ?? '';
			$attr[1][] = json_decode($tmp[1] ?? '[]', true);
		}
		
		// show and hide
		$hide = [];
		foreach($show as $k=>$v){
			$tmp = $v? $this->split($v, 'space'): '';
			$hide[] = (in_array('hidden', $tmp) && $col_en[$k] != $this->id)? 1: 0;
			
			foreach(array_intersect(['disabled', 'disabled-create', 'disabled-modify'], $tmp) as $w){
				$attr[1][$k][$w] = 1;
			}
		}
		
		$this->col_en = $col_en;
		$this->col_ch = $label[0];
		$this->info = $label[1];
		$this->join = $chain;
		$this->show = $show;
		$this->hide = $hide;
		$this->type = $attr[0];
		$this->attr = $attr[1];
		$this->auth = $auth;
		$this->data = [];
		$this->tree = $tree;
		$this->config = $config;
		
		$this->config['root'] = $this->split($this->config['root'] ?? '');
		$this->set_table($this->config['table'] ?? []);
		
		$this->col_num = $this->count($col_en);
		$this->uid = 0;
		
		$this->tpl = new Yatp(__DIR__ . '/assets/view.html');
		
		$this->decodeJson();
	}
	
	public function script(){
		
		$lang = new Yatp(__DIR__ . '/assets/lang.php');
		echo file_get_contents(__DIR__ . '/assets/script.js') . $lang->render(false);
	}
	
	public function css(){
		
		$this->tpl->block('css')->render();
	}
	
	public function decodeJson(){
		
		$request = $_REQUEST;
		
		if(isset($request['jdata'])){
			$jdata = json_decode($request['jdata'], true);
			
			//must keep keys 'data' and 'where', but remove empty array in where key
			//array( 'data' => array())                   got error
			//array( 'where' => array())                  got error
			//array( 'where' => array( 'AND' => array())) got error
			//do not remove empty array in data key
			//array( 'data' => array(name=>''))           will be removed
			
			$method = $jdata['method'] ?? '';
			$pdata = [
				'data' => $jdata['pdata']['data'] ?? [],
				'where' => array_filter($jdata['pdata']['where'] ?? []),
			];
			
			if(in_array($method, ['create', 'modify', 'delete'])){
				// unset disabled cols when create and modify
				for($i = 0; $i < $this->col_num; $i++){
					if(($this->attr[$i]['disabled'] ?? 0) || ($this->attr[$i]['disabled-' . $method] ?? 0)){
						unset($pdata['data'][$this->col_en[$i]]);
					}
				}
			}
			
			$this->act = $method;
			$this->arg = $pdata;
			$this->req = $jdata;
		}else{
			$this->act = $request['method'] ?? '';
			$this->arg = '';
			$this->req = $request;
		}
	}
	
	public function reviewTool(){
		
		if(!$this->auth[0]){ return;}
		
		$th = [];
		$filter = [];
		for($i = 0; $i < $this->col_num; $i++){
			// order settings
			if(!$this->hide[$i]){
				$th[] = [
					'class' => $this->show[$i] . (($this->type[$i] != 'value')? ' yb-order': ''),
					'name' => $this->col_en[$i],
					'text' => $this->col_ch[$i],
				];
			}
			
			if(in_array($this->type[$i], ['select', 'radiobox', 'checkbox'])){
				$tmp = $this->join[$i];
				$data = $this->database->select($tmp[0], [$tmp[1], $tmp[2]], $tmp[3]);
				$tpl = [];
				foreach($data?: [] as $v){
					$text = $v[$tmp[1]];
					if($this->attr[$i]['i18n'] ?? 0){
						$text = $text? _($text): '';
					}
					if($text){
						// JS won't ensure the order of numeric keys
						$tpl[] = [$v[$tmp[2]], $text];
					}
				}
				$tpl = json_encode($tpl, JSON_UNESCAPED_UNICODE);
				$this->join[$i][4] = $tpl;
			}
		}
		
		foreach($this->config['filter'] ?? [] as $k=>$v){
			$tmp = [];
			preg_match('/[\w]+/', $k, $tmp);
			$col = $tmp[0] ?? '';
			$i = array_flip($this->col_en)[$col] ?? -1;
			if($i != -1){
				$tpl = '';
				if(in_array($this->type[$i], ['value'])){
					continue;
				}else if(in_array($this->type[$i], ['datepicker'])){
					$func = 'datepicker';
					$tpl = $this->attr[$i]['format'] ?? 'Y-m-d';
				}else if($this->join[$i]){
					if(in_array($this->type[$i], ['autocomplete', 'module'])){
						$func = 'autocomplete';
					}else{
						$func = 'checkbox';
						$tpl = $this->join[$i][4];
					}
				}else{
					$func = 'text';
				}
				
				$value = $v;
				
			}else{
				if(is_array($v)){
					$func = 'checkbox';
					$tpl = [];
					foreach($v as $k2=>$v2){
						$tpl[] = [$k2, $v2];
					}
					$tpl = json_encode($tpl, JSON_UNESCAPED_UNICODE);
				}else{
					$func = $v;
					if($func == 'datepicker'){
						$tpl = 'Y-m-d';
					}
				}
				
				$value = '';
			}
			
			$tmp = $this->split($k, 'label');
			$filter[] = [
				'value' => $value,
				'meta' => $tmp[1] ?? $this->col_ch[$i] ?? $tmp[0] ?? '',
				'name' => $tmp[0] ?? '',
				'func' => '_' . $func,
				'uid' => $this->getUid(),
				'arg' => json_encode([
					'tpl' => $tpl,
					'max' => 20,
					'url' => $this->url,
				]),
			];
		}
		
		$this->tpl->block('main')->assign([
			'unique_id' => $this->unique_id,
			'url'       => $this->url,
			'tr'        => '',
			'th'        => $this->tpl->block('main.th')->nest($th),
			'search'    => $this->config['search'] ?? $this->tpl->block('main.search')->render(false),
			'filter'    => $this->tpl->block('filter')->nest($filter),
			'modal'     => $this->genFormModal(),
			'config'    => json_encode([
				'create_more' => $this->config['create_more'] ?? false,
				'single' => $this->config['single'] ?? false,
				'module' => $this->config['module'] ?? [],
				'search_adv' => $this->req['query'] ?? [],
				'admin' => $this->config['admin'] ?? '',
				'tree' => $this->col_en[$this->tree['col']] ?? '',
				'type' => $this->type,
				'max' => $this->config['perpage'] ?? 50,
				'col' => $this->col_en,
				'auth' => $this->auth,
				'id' => $this->id,
			], JSON_UNESCAPED_UNICODE),
		])->render();
	}
	
	public function review($pdata, $callback = ''){
		
		$datas = $this->getData($pdata);
		
		// apply outer data
		$this->apply($datas);
		
		// tree view
		$this->tree($datas);
		
		foreach($datas['data'] as $k=>$v){
			for($i = 0; $i < $this->col_num; $i++){
				if($this->attr[$i]['i18n'] ?? 0){
					$text = $v[$this->col_en[$i]] ?? '';
					$text = $text? _($text): '';
					$datas['data'][$k][$this->col_en[$i]] = $text;
				}
			}
		}
		
		// custom callback before rendering
		if($callback){
			$datas = call_user_func($callback, $datas);
		}
		
		$style = $this->req['style'] ?? '';
		
		if($style == 'excel' && class_exists('PhpOffice\PhpSpreadsheet\Spreadsheet')){
			$width = [];
			for($i = 0; $i < $this->col_num; $i++){
				// not always show id
				$show = $this->split($this->show[$i], 'space');
				if(!in_array('hidden', $show)){
					$width[$i] = str_replace('w', '', array_values(preg_grep('/^w[\d]+$/', $show))[0] ?? '');
				}
			}
			
			$out = [];
			foreach($datas['data'] as $k=>$v){
				$tmp = [];
				foreach($width as $i=>$w){
					// raw() is an array
					$tmp[] = is_array($v[$this->col_en[$i]])?
						str_replace('<br>', "\n", (htmlspecialchars_decode($v[$this->col_en[$i]][0], ENT_NOQUOTES))):
						$v[$this->col_en[$i]];
				}
				$out[] = $tmp;
			}
			\PhpOffice\PhpSpreadsheet\Cell\Cell::setValueBinder(new \PhpOffice\PhpSpreadsheet\Cell\AdvancedValueBinder());
			$sheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
			$c = 'A';
			foreach($width as $i=>$w){
				$sheet->getSheet(0)->getColumnDimension($c)->setWidth($w?: 100, 'px');
				$sheet->getSheet(0)->setCellValue($c . '1', $this->col_ch[$i]);
				$c++;
			}
			$sheet->getSheet(0)->fromArray($out, NULL, 'A2');
			$writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($sheet);
			
			header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
			header('Content-Disposition:filename=' . 'Export_' . date('YmdHis') . '.xlsx');
			$writer->save('php://output');
			exit;
		}
		
		$th = array_map(function($v){
			return ['text' => $v];
		}, $this->col_ch);
		
		$block = $style? 'table.print': 'main';
		
		$tr = [];
		
		foreach($datas['data'] as $k=>$v){
			$td = [];
			for($i = 0; $i < $this->col_num; $i++){
				if($style == '' && $this->hide[$i]) continue;
				$tree = (($this->tree['col'] === $i)? ' yb-tree func': '');
				$link = $tree && ($this->e($v[$this->col_en[$i]] ?? '') != '');
				$td[] = [
					'class' => $this->show[$i] . $tree,
					'name' => $this->col_en[$i],
					'text' => ($link? '<a href="#">(': '') . $this->e($v[$this->col_en[$i]] ?? '') . ($link? ')</a>': ''),
				];
			}
			
			$tree = $this->tree['col']? ($this->tree['sub'][2][$v[$this->id]] ?? ''): '';
			$tr[] = [
				'td' => $this->tpl->block($block . '.td')->nest($td)->render(false),
				'attr' => ($tree? ('data-sub="' . $v[$this->col_en[$this->tree['col']]] . '"'): '') . 'data-id="' . $v['__' . $this->id] . '" class="yb-row ' . $tree . '"',
			];
		}
		
		switch($style){
			case 'print':
			case 'excel':
				$html = $this->tpl->block($block)->assign([
					'th' => $this->tpl->block($block . '.th')->nest($th),
					'tr' => $this->tpl->block($block . '.tr')->nest($tr),
				]);
				break;
				
			default:
				$html = $this->tpl->block($block . '.tr')->nest($tr);
				break;
		}
		$html = $html->render(false);
		
		if($style == 'excel'){
			
			header('Content-type:application/vnd.ms-excel;');
			header('Content-Disposition:filename=' . 'Export_' . date('YmdHis') . '.xls');
			$result = $html;
		}else{
			$result = ['code' => 0, 'data' => $html, 'cnt' => $this->count($datas['data'])];
			$result = json_encode($result, JSON_UNESCAPED_UNICODE);
		}
		
		return $result;
	}
	
	public function create($pdata){
		
		if(!$this->auth[1]){ return;}
		
		$auto_increment = \Box::obj('db')->query('SHOW COLUMNS FROM `' . $this->table_o . '` WHERE `Extra` LIKE \'%auto_increment%\'')->fetchAll(\PDO::FETCH_ASSOC);
		
		if($auto_increment || $this->count($this->ids) > 1){
			unset($pdata['data'][$this->id]);
		}
		
		if(!$auto_increment){
			// user defined id and check
			$tmp = [];
			foreach($this->ids as $v){
				$tmp[] = $pdata['data'][$v];
			}
			$id = implode('_', $tmp);
			
			$r = $this->database->select($this->table_o, '*',
				\Medoo\Medoo::raw('WHERE ' . $this->concat_id . ' = :id', [':id' => $id])
			);
			
			if($r){
				$result = ['code' => 1, 'text' => '編號重複'];
				return json_encode($result, JSON_UNESCAPED_UNICODE);
			}
		}
		
		$r = $this->database->insert($this->table_o, $pdata['data']);
		
		if($r && $r->rowCount()){
			if($auto_increment){
				$id = $this->database->id();
			}
			$result = ['code' => 0, 'data' => $id, 'text' => '新增成功'];
		}else{
			$result = ['code' => 1, 'text' => '操作失敗'];
		}
		
		return json_encode($result, JSON_UNESCAPED_UNICODE);
	}
	
	public function modify($pdata){
		
		if(!$this->auth[2]){ return;}
		
		$id = $pdata['data'][$this->id];
		unset($pdata['data'][$this->id]);
		
		$r = $this->database->update($this->table_o, $pdata['data'],
			\Medoo\Medoo::raw('WHERE ' . $this->concat_id . ' = :id', [':id' => $id])
		);
		
		if($r && $r->rowCount()){
			$result = ['code' => 0, 'data' => $id, 'text' => '已儲存'];
		}else{
			$result = ['code' => 0, 'data' => $id, 'text' => '已儲存, 無任何變更'];
		}
		
		return json_encode($result, JSON_UNESCAPED_UNICODE);
	}
	
	public function delete($pdata){
		
		if(!$this->auth[3]){ return;}
		
		$id = $pdata['data'][$this->id];
		unset($pdata['data'][$this->id]);
		
		$r = $this->database->delete($this->table_o,
			\Medoo\Medoo::raw('WHERE ' . $this->concat_id . ' = :id', [':id' => $id])
		);
		
		if($r && $r->rowCount()){
			$result = ['code' => 0, 'data' => $id, 'text' => '已刪除'];
		}else{
			$result = ['code' => 1, 'text' => '操作失敗'];
		}
		
		return json_encode($result, JSON_UNESCAPED_UNICODE);
	}
	
	public function upload(){
		
		if(!$this->auth[1] && !$this->auth[2]){ return;}
		
		$tmp = [];
		
		if(!file_exists('upload')){
			mkdir('upload', 0755);
		}
		
		foreach($_FILES ?? [] as $file){
			// {"name":"new 2.txt","type":"text\/plain","tmp_name":"\/tmp\/phpRJ91Ks","error":0,"size":1295}
			$url = 'upload/' . time() . md5(rand());
			$tmp[] = ['url' => $url, 'name' => $file['name'], 'size' => $file['size']];
			move_uploaded_file($file['tmp_name'], $url);
		}
		
		$result['data'] = $tmp;
		
		return json_encode($result, JSON_UNESCAPED_UNICODE);
	}
	
	public function genFormModal(){
		
		$preset = array_replace_recursive(($this->config['preset'] ?? []), ($this->req['preset'] ?? []));
		
		$tr = [];
		for($i = 0; $i < $this->col_num; $i++){
			
			$tpl = '';
			$pre = $preset[$this->col_en[$i]] ?? ''; //靜態預設值(Preset)用於載入子分頁, 點擊新增時Reset可回復到預設值
			
			if(in_array($this->type[$i], ['value'])){
				continue;
			}
			
			if(in_array($this->type[$i], ['select', 'radiobox', 'checkbox'])){
				$tpl = $this->join[$i][4];
			}
			
			if(in_array($this->type[$i], ['module'])){
				$tpl = $this->attr[$i]['tpl'] ?? '';
			}
			
			if(in_array($this->type[$i], ['datepicker'])){
				$tpl = $this->attr[$i]['format'] ?? 'Y-m-d';
			}
			
			if(in_array($this->type[$i], ['json'])){
				$tpl = json_encode($pre);
				$pre = $this->e(json_encode($pre));
				$this->attr[$i]['max'] = $this->attr[$i]['keep'] ?? 0;
			}
			
			$td = $this->tpl->block('modal-detail.td.struct')->assign([
				'value' => $pre,
				'meta'  => $this->col_ch[$i],
				'name'  => $this->col_en[$i],
				'func'  => '_' . $this->type[$i],
				'info'  => $this->info[$i],
				'uid'   => $this->getUid(),
				'arg' => json_encode([
					'tpl' => $tpl,
					'max' => $this->attr[$i]['max'] ?? 1,
					'url' => $this->url,
				], JSON_UNESCAPED_UNICODE),
			]);
			
			$tmp = $this->split($this->show[$i], 'space');
			$arr = [];
			foreach($tmp as $v){
				if(in_array($v, ['hidden-create', 'hidden-modify', 'disabled', 'disabled-create', 'disabled-modify'])){
					$arr[] = $v;
				}
			}
			
			if(in_array($this->type[$i], ['hidden'])){
				$arr[] = 'hidden';
			}
			
			$tr[] = [
				'class' => implode(' ', $arr),
				'td' => array($td),
			];
		}
		
		$tpl = $this->tpl->block('modal-detail')->assign([
			'unique_id' => $this->unique_id,
			'html' => $this->config['modal']['html'] ?? '',
			'tr' => $this->tpl->block('modal-detail.tr')->nest($tr),
		]);
		
		return $tpl;
	}
	
	public function getJson($pdata){
		
		if(!$this->auth[0]){ return;}
		
		$ac = $pdata['where']['[a~]'] ?? $pdata['where']['[a=]'] ?? 0;
		if($ac){
			for($i = 0; $i < $this->col_num; $i++){
				if($this->col_en[$i] == $ac[0]){
					$arr_tmp = $this->join[$i];
					$table = $arr_tmp[0];
					$arr_col = [
						$arr_tmp[1] . '(label)',
						$arr_tmp[2] . '(val)', // 'value' will be inserted into the input automatically, 'val' won't
					];
					
					$key = $ac[1] ?? '';
					if($pdata['where']['[a~]'] ?? 0){
						$where = ['LIMIT' => 10];
						foreach($this->split($key, 'space') as $k=>$v){
							// treat wildcards as normal string (medoo > 2.1.3)
							$v = str_replace(['_', '[', ']', '%', '^'], ['\_', '\[', '\]', '\%', '\^'], $v);
							
							$match['AND'][$arr_tmp[1] . '#muti keyword' . $k] = $v;
							$fuzzy['AND'][$arr_tmp[1] . '[~] #muti keyword' . $k] = $v;
						}
						
						$pdata['where'] = array_replace_recursive($where, $match, $arr_tmp[3]);
						$arr1 = $this->database->select($table, $arr_col, $pdata['where']);
						$pdata['where'] = array_replace_recursive($where, $fuzzy, $arr_tmp[3]);
						$arr2 = $this->database->select($table, $arr_col, $pdata['where']);
						
						$tmp = [];
						foreach($arr1 as $k=>$v){
							$tmp[$v['val']] = 1;
						}
						foreach($arr2 as $k=>$v){
							if($tmp[$v['val']] ?? 0){
								// duplicate
							}else{
								$arr1[] = $v;
							}
						}
						
						$result['data'] = array_slice($arr1, 0, 10);
						
					}else if($pdata['where']['[a=]'] ?? 0){
						$where = [$arr_tmp[2] => $key];
						$pdata['where'] = array_replace_recursive($where, $arr_tmp[3]);
					}
					
					break;
				}
			}
			
		}else{
			$table = $this->table;
			$arr_col = $pdata['data']?: '*';
		}
		
		$result['data'] = $result['data'] ?? $this->database->select($table, $arr_col, $pdata['where']);
		
		return json_encode($result, JSON_UNESCAPED_UNICODE);
	}
	
	public function getData($pdata){
		// echo json_encode($pdata);
		if(!$this->auth[0]){ return;}
		
		$this->set_table([$this->table => $this->config['table'][$this->table] ?? '']);
		
		$arr_search = [];
		$arr_chain = [];
		$arr_col = [];
		
		for($i = 0; $i < $this->col_num; $i++){
			// skip
			if($this->type[$i] == 'value') continue;
			$arr_col[$i] = $this->table . '.' . $this->col_en[$i];
		}
		for($i = 0; $i < $this->col_num; $i++){
			// skip
			if($this->type[$i] == 'value') continue;
			if($this->join[$i]){
				if(!in_array($this->type[$i], ['checkbox', 'autocomplete', 'module'])){
					$arr_tmp = $this->join[$i];

					#join 2個欄位以上
					if(stripos($arr_tmp[2],";")){
						$arr_tmp2 = explode(';', $arr_tmp[2]);
						$arr_col[$i] = 't' . $i . '.' . $arr_tmp[1] . '(' . $this->col_en[$i] . ')';
						$joinArr = [];
						foreach ($arr_tmp2 as $key => $value) {

							#join 欄位=固定位
							if(stripos($value,"&")){
								$arr_tmp3 = explode('&', $value);
								$joinArr['AND'] = [$arr_tmp3[0] => $arr_tmp3[1]];
							}else{
								$joinArr[$value] = $value;
							}
							
						}
						$arr_chain['[>]' . $arr_tmp[0] . '(t' . $i . ')'] = $joinArr;
					}else{

						$arr_col[$i] = 't' . $i . '.' . $arr_tmp[1] . '(' . $this->col_en[$i] . ')';
						$arr_chain['[>]' . $arr_tmp[0] . '(t' . $i . ')'] = [$this->col_en[$i] => $arr_tmp[2]];
					}
				}
			}
			// keep original id
			$arr_col[$i + $this->col_num] = $this->table . '.' . $this->col_en[$i] . '(__' . $this->col_en[$i] . ')';
		}
		
		if($this->tree['col'] !== null){
			$col = $this->col_en[$this->tree['col']];
			$arr_tmp = $this->join[$this->tree['col']];
			$datas = $this->database->select($arr_tmp[0], [$arr_tmp[1], $arr_tmp[2], $col]);
			
			$tmp = [];
			foreach($datas as $v){
				$tmp[$v[$this->id]] = $v[$col];
			}
			
			$sub = $this->treeSub($tmp);
			$order = $this->flatten($this->toTree($tmp));
			
			$offset = [];
			foreach($order as $k=>$v){
				$offset[$v] = explode(',', $k)[1];
			}
			
			$this->tree['offset'] = $offset;
			$this->tree['sub'] = $sub;
		}
		
		// select only descendant
		if($this->config['root'][0]){
			$arr = [];
			foreach($this->config['root'] as $v){
				$arr = array_merge($arr, $this->tree['sub'][1][$v] ?? []);
			}
			// include self
			$id = $pdata['where']['AND'][$this->id] ?? 0;
			$ids = array_merge($arr, $this->config['root']);
			
			if($id){
				if(!is_array($id)){
					$id = [$id];
				}
				if($this->count(array_diff($id, $ids)) != 0){
					// invalid user
					exit;
				}
			}else{
				$pdata['where']['AND'][$this->id] = $ids;
			}
		}
		
		// add table name, skip specified table
		foreach($pdata['where']['AND'] ?? [] as $k=>$v){
			if(!preg_match('/\./', $k)){
				$pdata['where']['AND'][$this->table . '.' . $k] = $v;
				unset($pdata['where']['AND'][$k]);
			}
		}
		
		//for search
		if(isset($pdata['where']['SEARCH'])){
			$keyword = $this->split($pdata['where']['SEARCH'], 'space');
			
			foreach($keyword as $k=>$word){
				if($word){
					for($i = 0; $i < $this->col_num; $i++){
						// skip
						if(in_array($this->type[$i], ['value', 'password', 'uploadfile', 'datepicker'])) continue;
						if($this->tree['col'] === $i) continue;
						
						if(in_array($this->type[$i], ['checkbox', 'autocomplete', 'module'])){
							$arr_tmp = $this->join[$i];
							$ids = $this->database->select($arr_tmp[0], $arr_tmp[2], [$arr_tmp[1] . '[~]' => $word, 'LIMIT' => 1000]);
							if($ids){
								// find in a comma separated string
								$ids = array_map(function($r){
									return '(^|,)' . $r . '(,|$)';
								}, $ids);
								$arr_search[$this->table . '.' . $this->col_en[$i] . '[REGEXP]'] = implode('|', $ids);
							}
							
						}else if($this->join[$i]){
							$arr_tmp = $this->join[$i];
							$arr_search['t' . $i . '.' . $arr_tmp[1] . '[~]'] = $word;
						}else{
							$arr_search[$this->table . '.' . $this->col_en[$i] . '[~]'] = $word;
						}
					}
					$pdata['where']['AND #fuzzy search']['OR #muti keyword' . $k] = $arr_search;
				}
			}
			unset($pdata['where']['SEARCH']);
		}
		
		//search advance
		if(isset($pdata['where']['SEARCH_ADV'])){
			
			$adv = $pdata['where']['SEARCH_ADV'];
			foreach($adv['AND'] ?? [] as $k=>$v){
				//table.id (join)
				$i = array_flip($this->col_en)[$k] ?? 0;
				if(in_array($this->type[$i], ['checkbox', 'autocomplete', 'module'])){
					$ids = explode(',', $v);
					if($ids){
						// find in a comma separated string
						$ids = array_map(function($r){
							return '(^|,)' . $r . '(,|$)';
						}, $ids);
						$adv['AND #adv'][$this->table . '.' . $this->col_en[$i] . '[REGEXP]'] = implode('|', $ids);
					}
				}else{
					$adv['AND #adv'][$this->table . '.' . $k] = $v;
				}
				unset($adv['AND'][$k]);
			}
			unset($pdata['where']['SEARCH_ADV']);
			
			if(is_array($adv)){
				$pdata['where'] = array_merge_recursive($pdata['where'], $adv);
			}
		}
		// check and merge SEARCH_CUS
		foreach($pdata['where']['SEARCH_CUS'] ?? [] as $k=>$v){
			$tmp = [];
			preg_match('/[\w]+/', $k, $tmp);
			$col = $tmp[0] ?? '';
			if(in_array($col, $this->col_en) && $v){
				$i = array_flip($this->col_en)[$col] ?? 0;
				if($this->join[$i]){
					// find in a comma separated string
					$ids = $this->split($v);
					$ids = array_map(function($r){
						return '(^|,)' . $r . '(,|$)';
					}, $ids);
					$pdata['where']['AND #cus'][$this->table . '.' . $k . '[REGEXP]'] = implode('|', $ids);
				}else{
					$pdata['where']['AND #cus'][$this->table . '.' . $k] = $v;
				}
			}
		}
		unset($pdata['where']['SEARCH_CUS']);
		
		// order
		if(!($pdata['where']['ORDER'] ?? 0)){
			// default order
			$pdata['where']['ORDER'] = ($this->count($this->ids) > 1)? []: [$this->id => 'DESC'];
		}
		
		// add table name
		foreach($pdata['where']['ORDER'] ?? [] as $k=>$v){
			$pdata['where']['ORDER'][$this->table . '.' . $k] = $v;
			unset($pdata['where']['ORDER'][$k]);
		}
		
		$where = array_filter($pdata['where'] ?? []);

		# 商品管理時，當 material.high_storage 排序時，則單以 high_storage 做排序
		if(isset($where["ORDER"])){
			$oldOrder = $where["ORDER"];
			$newOrder = [];
			foreach ($oldOrder as $key => $value) {
				if($key == 'material.high_storage'){
					$newOrder['high_storage'] = $value;
				}else{
					$newOrder[$key] = $value;
				}
			}
			$where["ORDER"] = $newOrder;

		}
		// echo '<br />';
		// echo '<br />';
		// print_r($arr_col) ; 
		// print_r($arr_chain) ; 
		// echo $this->table;
		// exit;
		if($arr_chain){
			$datas = $this->database->select($this->table, $arr_chain, $arr_col, $where);
		}else{
			$datas = $this->database->select($this->table, $arr_col, $where);
		}
		
		if($datas){
			$arr_mark = [];
			$cnt_datas = $this->count($datas);
			
			for($j = 0; $j < $this->col_num; $j++){
				switch($this->type[$j]){
					case 'checkbox':
						$arr_mark[$j] = [];
						$arr_tmp = $this->join[$j];
						$datas_checkbox = $this->database->select($arr_tmp[0], [$arr_tmp[1], $arr_tmp[2]]);
						
						foreach($datas_checkbox as $arr){
							$arr_mark[$j][$arr[$arr_tmp[2]]] = $arr[$arr_tmp[1]];
						}
						break;
					case 'autocomplete':
					case 'module':
						// collect ids in data list for preventing too much results
						$ids = [];
						for($i = 0; $i < $cnt_datas; $i++){
							$tmp = explode(',', $datas[$i][$this->col_en[$j]]);
							foreach($tmp as $v){
								$ids[$v] = $v;
							}
						}
						$ids = array_values($ids);
						
						$arr_mark[$j] = [];
						$arr_tmp = $this->join[$j];
						$datas_checkbox = $this->database->select($arr_tmp[0], [$arr_tmp[1], $arr_tmp[2]], [$arr_tmp[2] => $ids]);
						
						foreach($datas_checkbox as $arr){
							$arr_mark[$j][$arr[$arr_tmp[2]]] = $arr[$arr_tmp[1]];
						}
						break;
					case 'uploadfile':
					case 'datepicker':
					case 'value':
						$arr_mark[$j] = 1;
						break;
					case 'json':
						$arr_tmp = $this->config['preset'][$this->col_en[$j]] ?? [];
						$tmp = [];
						foreach($arr_tmp as $k=>$v){
							$txt = explode(',', $k);
							$tmp[($txt[0] ?? '')] = ($txt[1] ?? '')?: ($txt[0] ?? '');
						}
						$arr_mark[$j] = $tmp;
						break;
				}
			}
			
			for($i = 0; $i < $cnt_datas; $i++){
				//translate
				foreach($arr_mark as $key=>$arr){
					
					$idx = $key;
					$key = $this->col_en[$idx];
					
					switch($this->type[$idx]){
						case 'checkbox':
						case 'autocomplete':
						case 'module':
							if($datas[$i][$key]){
								$arr_vtmp = $this->split($datas[$i][$key]);
								$arr_result = [];
								
								foreach($arr_vtmp as $val){
									$arr_result[] = $this->e($arr[$val] ?? 0);
								}
								
								$datas[$i][$key] = $this->raw(implode('<br>', $arr_result));
							}
							break;
							
						case 'uploadfile':
							$arr = json_decode($datas[$i][$key], true);
							if(is_array($arr)){
								// check file
								foreach($arr as $k=>$v){
									
									$ext = strtolower(explode('.', $v['name'])[1] ?? 'na');
									$arr[$k]['ext'] = $ext;
									
									if(file_exists($v['url']) && explode('/', mime_content_type($v['url']))[0] == 'image'){
										$arr[$k]['icon'] = 'hidden';
									}else{
										$arr[$k]['img'] = 'hidden';
									}
								}
								$datas[$i][$key] = $this->raw($this->tpl->block('crop-img')->nest($arr)->render(false));
							}
							break;
							
						case 'json':
							$tmp = [];
							foreach($arr as $k=>$v){
								$arr = json_decode($datas[$i][$key], true);
								$tmp[] = $this->e($v) . ': ' . $this->e($arr[$k] ?? '');
							}
							$datas[$i][$key] = $this->raw(implode('<br>', $tmp));
							break;
							
						case 'datepicker':
							if($datas[$i][$key]){
								$datas[$i][$key] = date($this->attr[$idx]['format'] ?? 'Y-m-d', (int)$datas[$i][$key]);
							}
							break;
							
						case 'value':
							$datas[$i][$key] = '';
							break;
					}
				}
			}
		}
		
		$result = ['data' => $datas, 'cnt' => $this->count($datas)];
		
		return $result;
	}
	
	public function getUid(){//get unique id for html tags for this file
		return $this->unique_id . '_uid_' . $this->uid++;
	}
	
	public function render($option = []){
		$this->config = array_merge($this->config, $option);
		$this->reviewTool();
	}
	
	public function raw($str){
		return [$str];
	}
	
	protected function e($str){
		return is_array($str)? ($str[0] ?? ''): htmlspecialchars($str);
	}
	
	protected function treeSub($tree){
		
		$result = [];
		$arr = [];
		// unique pid list
		$p = [];
		
		foreach($tree as $k=>$v){
			if(empty($tree[$k])){
				$tree[$k] = '0';
			}
			
			$p[$v] = $v;
			
			// init
			$arr[$k] = [];
		}
		
		// direct children count
		foreach($tree as $k=>$v){
			$arr[$v][$k] = $k;
		}
		$result[] = $arr;
		
		// all children count
		foreach($p as $parent){
			foreach($arr as $k => $children){
				if($children[$parent] ?? 0){
					$arr[$k] = $children + $arr[$parent];
				}
			}
		}
		$result[] = $arr;
		
		// tree view class
		$arr = [];
		foreach($tree as $k => $v){
			$arr[$k] = 'p_' . ((in_array($k, $this->config['root']) || !isset($tree[$v]))? '0': $v);
		}
		$result[] = $arr;
		
		return $result;
	}
	
	protected function toTree($array){
		$flat = [];
		$tree = [];
		foreach($array as $child => $parent){
			if(!isset($flat[$child])){
				$flat[$child] = [];
			}
			if(isset($array[$parent])){
				$flat[$parent][$child] = &$flat[$child];
			}else{
				$tree[$child] = &$flat[$child];
			}
		}
		return $tree;
	}
	
	protected function flatten($arr, $l = 0){
		$result = [];
		foreach($arr as $key=>$val){
			$result[$key . ',' . $l] = $key;
			if(is_array($val) && $this->count($val)){
				$result = array_merge($result, $this->flatten($val, $l+1));
			}
		}
		return $result;
	}
	
	public function bind($data){
		
		foreach($data as $arr){
			foreach($arr as $k=>$v){
				if($k != $this->id){
					$this->data[$k][$arr[$this->id]] = $v;
				}
			}
		}
	}
	
	protected function apply(&$data){
		
		foreach($data['data'] as $k=>$v){
			foreach($this->data as $key=>$arr){
				$data['data'][$k][$key] = $this->data[$key][$v[$this->id]] ?? '';
			}
		}
	}
	
	protected function tree(&$data){
		
		if($this->tree['col'] !== null){
			$offset = $this->tree['offset'];
			$sub = $this->tree['sub'][1];
			$dsub = $this->tree['sub'][0];
			$col = $this->col_en[$this->tree['col']];
			
			foreach($data['data'] as $k=>$v){
				foreach($this->config['sum'] ?? [] as $key){
					$this->data[$key][$v[$this->id]] = $v[$key] ?? 0;
				}
			}
			
			foreach($data['data'] as $k=>$v){
				foreach($this->config['sum'] ?? [] as $key){
					$sum = 0;
					$sum += (float)($this->data[$key][$v[$this->id]] ?? 0);
					foreach($sub[$v[$this->id]] as $c){
						$sum += (float)($this->data[$key][$c] ?? 0);
					}
					$data['data'][$k][$key] = $sum;
				}
				
				$data['data'][$k][$col] = ($offset[$v[$this->id]] < ($this->config['level'] ?? 1000) -1)? $this->count($dsub[$v[$this->id]]): '';
			}
		}
	}
	
	protected function split($str, $case = ''){
		
		$result = [];
		$regex = [
			'/,[\s]*/', // with a leading comma
			'/[\s]+/',  // at least one space
		];
		switch($case){
			case 'chain':
				$arr = preg_split($regex[0], $str, 4);
				$arr[3] = json_decode($arr[3] ?? '[]', true);
				$result = $arr;
				break;
			case 'label':
				$result = preg_split($regex[0], $str, 2);
				break;
			case 'space':
				$result = preg_split($regex[1], trim($str));
				break;
			default:
				$result = preg_split($regex[0], $str);
				break;
		}
		
		return $result;
	}
	
	protected function count($tmp){
		// php 7.2 issue
		return count((array)$tmp);
	}
	
	public function set_table($arr){
		
		foreach($arr as $table=>$value){
			if(is_array($value)){
				// create temp table from arr
				$col = [];
				foreach($value[0] ?? [] as $k=>$v){
					$col[] = '`' . $k . '` VARCHAR(100) NOT NULL';
				}
				
				$this->database->query('CREATE OR REPLACE TEMPORARY TABLE `' . $table . '` (' . implode(',', $col) . ')')->fetchAll();
				$this->database->insert($table, $value);
				
			}else if(is_a($value, 'Medoo\Raw')){
				// create view with param
				if($this->query('EXPLAIN ' . $value->value, $value->map)){
					$this->query('CREATE OR REPLACE VIEW `' . $table . '` AS ' . $value->value, $value->map)->fetchAll();
				}
				
			}else if($this->database->query('EXPLAIN ' . $value)){
				// create view
				$this->database->query('CREATE OR REPLACE VIEW `' . $table . '` AS ' . $value)->fetchAll();
			}
		}
	}
	
	public function query($sql, $bind)
	{
		$arr = [];
		foreach($bind as $k=>$v){
			if(is_array($v)){
				// deal with where in
				$tmp = [];
				foreach($v as $k2=>$v2){
					$arr[$k . '_yapa' . $k2] = $v2;
					$tmp[] = $k . '_yapa' . $k2;
				}
				
				if($tmp){
					$sql = str_replace($k, implode(',', $tmp), $sql);
				}else{
					$sql = preg_replace('/[\S]+[\s]+IN[\s]+\([\s]*' . $k . '[\s]*\)/i', '1=1', $sql);
				}
			}else{
				$arr[$k] = $v;
			}
		}
		
		return $this->database->query($sql, $arr);
	}

	public function setJoin($newJoin = []){
		if(count($newJoin) >0 ){
			$chain = [];
			$tree = ['col' => null];
			foreach($newJoin as $k=>$v){
				$chain[] = $v? $this->split($v, 'chain'): '';
				// tree view check
				if($config['tree'] ?? 0){
					$tree['col'] = $config['tree'];
				}else{
					if(($chain[$k][0] ?? '') == $this->table){
						$tree['col'] = $k;
					}
				}
			}
			$this->join = $chain;
		}
	}
}
