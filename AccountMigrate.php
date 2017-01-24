<?php

/**
 * Перемещает данные в новый аккаунт.
 *
 * Перед запуском в новом аккаунте необходимо создать:
 * 1) Менеджеров с теми же именами.
 * 2) Типы задач в том же порядке
 * 3) Воронки и статусы в том же порядке
 *
 * Перемещает:
 * Сделки
 * Контакты
 * Компании
 * Примечания (кроме чатов и переходов по статусам)
 * Задачи
 *
 * Не переместятся:
 * Покупатели
 * Примечания типа чат, и типа 3 (Статус сделки изменен)
 *
 * Не достающие кастомные поля будут создаданны автоматически, с теми же именами.
 * Не существующие типы задач будут заменены на CALL (Связаться с клиентом)
 * !Важно. В сущностях не должны дублироваться названия полей, иначе могут возникнуть проблемы.
 * Поля от GA и прочие поля с непучтым полем code не создадутся.
 *
 * Class AccountMigrate
 */
class AccountMigrate {
	const MAX_ENTITY = 250;
	const LEADS_TYPE = 2;
	const CONTACTS_TYPE = 1;
	const COMPANIES_TYPE = 3;
	const TASKS_TYPE = 4;

	private $starttime;
	private $count_requests = 0;

	private $config = [];
	private $last_auth = [
		'from' => FALSE,
		'to' => FALSE
	];
	private $settings_key = NULL;

	private $entity_types = [
		'unsorted' => ['sip', 'mail', 'forms'],
		'leads', 'companies', 'contacts',
		'tasks',
		'notes' => ['contact', 'lead', 'company', 'task']
	];

	private $lang = [
		'ru' => [
			'unsorted' => 'Неразобранное',
			'leads' => 'Сделки',
			'contacts' => 'Контакты',
			'companies' => 'Компании',
			'tasks' => 'Задачи',
			'notes' => 'Примечания',

			'contact' => 'Контакт',
			'lead' => 'Сделка',
			'company' => 'Компаниия',
			'task' => 'Задача',
			'note' => 'Примечание',
		]
	];

	private $map = [];

	private function __construct($config) {
		$this->starttime = time();
		$this->config = $config;
	}

	/**
	 * Основной метод
	 * @param $config
	 * @return AccountMigrate
	 */
	public static function run($config) {
		return new self($config);
	}

	/**
	 * Получение всех данных из аккаунта
	 * @return array|bool
	 */
	public function get_all_data() {
		echo 'Экспорт всех данных...'.PHP_EOL;
		$starttime = time();
		$data = [];

		$this->settings_key = 'from';

		foreach($this->entity_types as $k => $type) {
			if(is_array($type)) {
				$data[$k] = $this->get_entities($k);
			} else {
				$data[$type] = $this->get_entities($type);
			}
		}

		echo 'Все сущности получены  за ' . (time() - $starttime) . 'с.'.PHP_EOL;
		return $data;
	}

	/**
	 * Возвращает данные по аккаунту
	 * @return mixed
	 */
	private function get_accounts_current() {
		$method = '/private/api/v2/json/accounts/current/';
		$link = $this->build_link($method);;

		$response = $this->send_request($link);

		return $response["data"]['response']['account'];
	}

	/**
	 * Получает все сущности по переданному типу
	 * @param string $type = leads | contacts | companies
	 * @return array
	 */
	private function get_entities($type) {
		$starttime = time();
		echo 'Получаем все '.$this->lang['ru'][$type].PHP_EOL;
		if($type == 'unsorted' ) {
			$params['page_size'] = self::MAX_ENTITY;
			$params['PAGEN_1'] = 1;
		} else {
			$params['limit_rows'] = self::MAX_ENTITY;
			$params['limit_offset'] = 0;
		}

		$all_chunks = [];
		if($type === 'notes') {
			foreach($this->entity_types[$type] as $notes_type) {
				$params['type'] = $notes_type;
				$chanks = $this->get_all_chunks($type, $params);
				$all_chunks = array_merge($all_chunks, $chanks);
			}
		} elseif($type === 'unsorted') {
			foreach($this->entity_types[$type] as $unsorted_type) {
				$params['categories'][0] = $unsorted_type;
				$all_chunks[$unsorted_type] = $this->get_all_chunks($type, $params);;
			}
		} else {
			$all_chunks = $this->get_all_chunks($type, $params);
		}

		echo 'Все '.$this->lang['ru'][$type]. ' получены за ' .(time() - $starttime). 'c.'.PHP_EOL;
		return $all_chunks;
	}

	/**
	 * Возвращает все части по 500 сущностей
	 * @param $type
	 * @param $params
	 * @return array
	 */
	private function get_all_chunks($type, $params) {
		$chunks = [];
		while($entities_chunk = $this->get_entity_chunk($type, $params)) {
			if($type == 'unsorted' ) {
				if(empty($entities_chunk['list'])) {
					break;
				}
				$params['PAGEN_1']++;
			} else {
				$params['limit_offset'] += self::MAX_ENTITY;
			}

			$chunks[] = $entities_chunk;
		}

		return $chunks;
	}

	/**
	 * Возвращает 500 сущностей по переданному типу
	 * @param $type
	 * @param $params
	 * @return mixed
	 */
	private function get_entity_chunk($type, $params) {
		if($type == 'unsorted' ) {
			$method = '/api/unsorted/list/';
			$params['api_key'] = $this->config[$this->settings_key]['account']['USER_HASH'];
			$params['login'] = $this->config[$this->settings_key]['account']['USER_LOGIN'];
		} else {
			if($type == 'companies') {
				$type = 'company';
			}
			$method = '/private/api/v2/json/'.$type.'/list/';
		}
		$link = $this->build_link($method, $params);

		$response = $this->send_request($link);
		$data = $response["data"];

		if($type == 'company') {
			$type = 'contacts';
		}
		return $data["response"][$type];
	}

	/**
	 * Перемещает даныне в новый аккаунт
	 * @return bool
	 * @throws Exception
	 */
	public function move() {
		$starttime = time();
		echo 'Перемещаем в новый аккаунт...'.PHP_EOL;

		$this->settings_key = 'from';
		$data['old'] = $this->get_accounts_current();

		$this->settings_key = 'to';
		$data['new'] = $this->get_accounts_current();
		$this->build_account_map($data['old'], $data['new']);

		foreach($this->entity_types as $k => $type) {
			if(is_array($type)) {
				$key = $k;
			} else {
				$key = $type;
			}

			$this->settings_key = 'from';
			$data = $this->get_entities($key);

			$this->settings_key = 'to';
			$this->send_entities($key, $data);
		}

		echo 'Все данные перемещены  за ' . (time() - $starttime) . 'с.'.PHP_EOL.PHP_EOL;

		return TRUE;
	}

	private function send_custom_fields($data) {
		$method = '/private/api/v2/json/fields/set/';
		$link = $this->build_link($method);

		$fields['request']['fields']['add'] = $data;

		$response = $this->send_request($link, $fields, 'CURLOPT_CUSTOMREQUEST');
		$data = $response["data"];

		return $data["response"]['fields']['add'];
	}

	/**
	 * Загружает сущности в аккаунт
	 * @param $type
	 */
	private function send_entities($type, $data) {
		$data = $this->filter_data($type, $data);

		$starttime = time();
		echo 'Отправляем все '.$this->lang['ru'][$type].PHP_EOL;

		foreach($data as $chunk_id => $chunk) {
			$response = $this->send_entity_chunk($type, $chunk);
			if(!in_array($type, ['notes', 'unsorted'])) {
				if(empty($map)) {
					$map = $this->build_map($chunk, $response);
				} else {
					$map += $this->build_map($chunk, $response);
				}
			}
		}

		if(isset($map)) {
			$this->map[$type] = $map;
		}
		echo 'Все '.$this->lang['ru'][$type]. ' отправлены за ' .(time() - $starttime). 'c.'.PHP_EOL;
	}

	/**
	 * Создает карту данных аккаунтов
	 * @param $old_account
	 */
	public function build_account_map($old_account, $new_account) {
		$starttime = time();
		$account_map = [];

		$fields_for_send = $this->build_custom_fields($old_account['custom_fields'], $new_account['custom_fields']);
		$this->send_custom_fields($fields_for_send);
		$new_account = $this->get_accounts_current();

		foreach($old_account['custom_fields'] as $type => $fields) {
			$account_map['fields'][$type] = $this->build_map(array_reverse($fields), $new_account['custom_fields'][$type]);
		}

		$account_map['id'] = [
			$old_account['id'] => $new_account['id']
		];

		$old_statuses = $this->get_statuses_by_pipelines($old_account['pipelines']);
		$new_statuses = $this->get_statuses_by_pipelines($new_account['pipelines']);

		$account_map['pipelines'] = $this->build_map($old_account['pipelines'], $new_account['pipelines']);
		$account_map['task_types'] = $this->build_map($old_account['task_types'], $new_account['task_types']);
		$account_map['statuses'] = $this->build_map($old_statuses, $new_statuses);
		$account_map['managers'] = $this->build_managers_map($old_account['users'], $new_account['users']);
		$account_map['fields'] = $this->build_fields_map($old_account['custom_fields'], $new_account['custom_fields']);

		echo 'Карта аккаунта создана за '. (time() - $starttime) .'c.'.PHP_EOL;
		$this->map['account'] = $account_map;
	}

	private function get_statuses_by_pipelines($pipelines) {
		foreach($pipelines as $pipeline) {
			foreach($pipeline['statuses'] as $status) {
				$statuses[] = $status;
			}
		}

		return $statuses;
	}

	/**
	 * Выделяем поля для отправки
	 * @param $old_data
	 * @param $new_data
	 * @return array
	 */
	private function build_custom_fields($old_data, $new_data) {
		$new_fields = [];

		foreach($old_data as $type => $fields) {
			foreach($fields as $field) {
				if(!empty($field['code'])) { // Пропускаем кастомные поля
					continue;
				}

				if(empty($this->search_item('name', $field['name'], $new_data[$type]))) {
					$field['element_type'] = $this->get_entity_id_by_type($type);
					$field['origin'] = 'migrate_script';
					$field['type'] = $field['type_id'];
					unset($field['type_id']);
					$new_fields[] = $field;
				}
			}
		}

		return $new_fields;
	}

	private function build_fields_map($old_fields, $new_fields) {
		$map = [];
		foreach($old_fields as $type => $o_fields) {
			foreach($o_fields as $o_filed) {
				if(!empty($o_filed['code'])) {
					$n_field = $this->search_item('code', $o_filed['code'], $new_fields[$type]);
				} else {
					$n_field = $this->search_item('name', $o_filed['name'], $new_fields[$type]);
				}

				if(!empty($n_field)) {
					if(!empty($o_filed['enums'])) {
						$map[$type][$o_filed['id']] = [
							'id' => $n_field['id']
						];

						$n_field['enums'] = array_flip($n_field['enums']);
						foreach($o_filed['enums'] as $k => $enum) {
							$map[$type][$o_filed['id']]['enums'][$k] = array_shift($n_field['enums']);
						}
					} else {
						$map[$type][$o_filed['id']] = $n_field['id'];
					}
				}
			}
		}

		return $map;
	}

	private function build_managers_map($old_users, $new_users) {
		$map = [];
		foreach($old_users as $o_user) {
			$n_user = $this->search_item('name', $o_user['name'], $new_users);
			$map[$o_user['id']] = $n_user['id'];
		}

		return $map;
	}

	private function search_item($key, $value, $new_data) {
		$target_item = NULL;
		foreach($new_data as $item) {
			if($item[$key] === $value) {
				$target_item = $item;
				break;
			}
		}

		return $target_item;
	}

	/**
	 * Возвращает карту на основе новых и старых данных
	 * @param $old_data
	 * @param $new_data
	 * @return mixed
	 */
	private function build_map($old_data, $new_data) {
		$map = [];

		$map['old'] = $this->get_map_data($old_data);
		$map['new'] = $this->get_map_data($new_data);

		return $this->filter_map($map);
	}

	private function get_map_data($data) {
		$map =[];
		foreach($data as $item) {
			$map[] = [
				'id' => $item['id'],
			];
		}

		return $map;
	}

	/**
	 * Приводит карту к удобному виду
	 * @param $data
	 * @return mixed
	 */
	private function filter_map($data) {
		foreach($data['old'] as $k => $item) {
			$data[$item['id']] = $data['new'][$k]['id'];
		}
		unset($data['new']);
		unset($data['old']);

		return $data;
	}

	/**
	 * Отправляет часть сущностей в соответствии с ограничением
	 * @param $type
	 * @param $data
	 * @return mixed
	 */
	private function send_entity_chunk($type, $data) {
		if($type === 'unsorted') {
			$method = '/api/unsorted/add';
			$params = [
				'api_key' => $this->config[$this->settings_key]['account']['USER_HASH'],
				'login' => $this->config[$this->settings_key]['account']['USER_LOGIN'],
			];
			$link = $this->build_link($method, $params);

			$entities['request'][$type] = $data;
		} else {
			if($type === 'companies') {
				$type = 'company';
			};

			$method = '/private/api/v2/json/'.$type.'/set/';
			$link = $this->build_link($method);

			if($type === 'company') {
				$type = 'contacts';
			};
			$entities['request'][$type]['add'] = $data;
		}

		$response = $this->send_request($link, $entities, 'CURLOPT_CUSTOMREQUEST');
		$data = $response["data"];

		return $data["response"][$type]['add'];
	}

	/**
	 * Готовит данные к отправке.
	 * Меняет id-шники. Формирует правильные массивы.
	 * @param $type
	 * @param $data
	 * @return array
	 */
	private function filter_data($type, $data) {
		$starttime = time();
		echo 'Формируем '.$this->lang['ru'][$type].PHP_EOL;
		if ($type == 'unsorted') {
			$tmp_data = [];
			foreach($data as $unsorted_type => &$chunks) {
				if(!empty($chunks)) {
					foreach($chunks as $chunk_id => &$chunk) {
						foreach ($chunk['list'] as &$item) {
							foreach($item['data'] as $type => &$item_data) {
								foreach($item_data as &$entity) {
									if(!empty($entity['notes'])) {
										$entity['notes'] = $this->filter_data_chunk('notes', $entity['notes']);
									}
								}
								$item_data = $this->filter_data_chunk($type, $item_data);
							}

							$new_item['add'] = $item;
						}

						$tmp_data[] = [
							'category' => $unsorted_type,
							'add' => $chunk['list']
						];

					}
				}
			}
			$data = $tmp_data;
		} else {
			foreach($data as $chunk_id => &$chunk) {
				$chunk = $this->filter_data_chunk($type, $chunk);
			}
		}

		echo $this->lang['ru'][$type]. ' сформированы за ' .(time() - $starttime). 'c.'.PHP_EOL;
		return $data;
	}

	/**
	 * Фильтрут часть данных
	 * @param $type
	 * @param $chunk
	 * @return mixed
	 */
	private function filter_data_chunk($type, $chunk) {
		foreach ($chunk as $k => &$item) {
			if(!empty($item['responsible_user_id'])) {
				if(isset($this->map['account']['managers'][$item['responsible_user_id']])) {
					$item['responsible_user_id'] = $this->map['account']['managers'][$item['responsible_user_id']];
				}
			}

			if (in_array($type, ['leads', 'contacts', 'companies'])) {

				if(!empty($item['pipeline_id'])) {
					$item['pipeline_id'] = $this->map['account']['pipelines'][$item['pipeline_id']];
				}

				if(!empty($item['status_id'])) {
					$item['status_id'] = $this->map['account']['statuses'][$item['status_id']];
				}

				if (!empty($item['tags'])) {
					$tags = '';
					foreach ($item['tags'] as $tag) {
						$tags .= $tag['name'] . ',';
					}

					$item['tags'] = $tags;
				}

				if ($type !== 'leads') {
					if (!empty($item['linked_leads_id'])) {
						foreach ($item['linked_leads_id'] as &$id) {
							$id = $this->map['leads'][$id];
						}
					}
				}

				if ($type == 'contacts') {
					if (!empty($item['linked_company_id'])) {
						$item['linked_company_id'] = $this->map['companies'][$item['linked_company_id']];
					}
				}

				if(!empty($item['custom_fields'])) {
					foreach ($item['custom_fields'] as &$field) {
						if (!empty($field['values'][0]['enum'])) {
							foreach ($field['values'] as &$value) {
								$value['enum'] = $this->map['account']['fields'][$type][$field['id']]['enums'][$value['enum']];
							}

							$field['id'] = $this->map['account']['fields'][$type][$field['id']]['id'];
						} else {
							$field['id'] = $this->map['account']['fields'][$type][$field['id']];
						}
					}
				}

			} else {
				if (!empty($item['element_id'])) {
					$item['element_id'] = $this->map[$this->get_entity_type_by_id($item['element_type'])][$item['element_id']];
				}

				if (!empty($item['account_id'])) {
					$item['account_id'] = $this->map['account']['id'][$item['account_id']];
				}

				if(!empty($item['created_user_id'])) {
					$item['created_user_id'] = $this->map['account']['managers'][$item['created_user_id']];
				}

				if ($type == 'tasks') {
					if (is_int($item['task_type']) || is_numeric($item['task_type'])) {
						if(isset($this->map['account']['task_types'][$item['task_type']])) {
							$item['task_type'] = $this->map['account']['task_types'][$item['task_type']];
						} else {
							$item['task_type'] = 'CALL'; // Поставим Call по умолчанию, если данный тип был удален
						}

						unset($chunk[$k]['result']);
					}
				}

				if ($type == 'notes') {
					if (in_array($item['note_type'], [1, 2, 3, 12])) {
						unset($chunk[$k]);
						continue;
					}
				}
			}
		}

		return $chunk;
	}

	/**
	 * Возвращает строковый тип по ID
	 * @param $id
	 * @return null|string
	 */
	private function get_entity_type_by_id($id) {
		switch($id) {
			case self::LEADS_TYPE:
				return 'leads';
				break;
			case self::CONTACTS_TYPE:
				return 'contacts';
				break;
			case self::COMPANIES_TYPE:
				return 'companies';
				break;
			case self::TASKS_TYPE:
				return 'tasks';
				break;
		}

		return NULL;
	}

	/**
	 * Возвращает id типа по его названию
	 * @param $type
	 * @return bool|int
	 */
	private function get_entity_id_by_type($type) {
		switch($type) {
			case 'leads':
				$return = self::LEADS_TYPE;
				break;
			case 'companies':
				$return = self::COMPANIES_TYPE;
				break;
			case 'contacts':
				$return = self::CONTACTS_TYPE;
				break;
			case 'tasks':
				$return = self::TASKS_TYPE;
				break;
			default:
				$return = FALSE;
		}

		return $return;
	}

	/**
	 * Генерирует ссылку по переданным параметрам
	 * @param $method
	 * @param null $params
	 * @return string
	 */
	private function build_link($method, $params = NULL) {
		$link = $this->config[$this->settings_key]['protocol'].
			$this->config[$this->settings_key]['subdomain'].'.'.
			$this->config[$this->settings_key]['domain'].
			$method;

		if(!empty($params)) {
			$link .= '?'.http_build_query($params);
		}

		return $link;
	}

	/**
	 * Авторизация
	 * @return bool
	 * @throws Exception
	 */
	private function auth() {
		$this->last_auth[$this->settings_key] = TRUE;

		$method = '/private/api/auth.php';
		$account = $this->config[$this->settings_key]['account'];
		$params['type'] = 'json';

		$link = $this->build_link($method, $params);

		$response = $this->send_request($link, $account, 'CURLOPT_POST');
		$data = $response["data"];

		if($data["response"]["auth"]) {
			$this->last_auth[$this->settings_key] = time();
			return TRUE;
		} else {
			throw new Exception('Не удалось авторизоваться в аккаунте '. $this->config[$this->settings_key]['subdomain']);
		}
	}

	/**
	 * Проверяет авторизацию
	 */
	private function check_auth() {
		return $this->last_auth[$this->settings_key] || (time() - $this->last_auth[$this->settings_key]) < (60 * 10);
	}

	/**
	 * Отправляет запрос.
	 * @param $link
	 * @param array $post_data
	 * @param string|bool|FALSE $type = FALSE | CURLOPT_POST | CURLOPT_CUSTOMREQUEST
	 * @param bool|FALSE $log
	 * @param array $headers
	 * @return array
	 */
	private function send_request($link, $post_data = [], $type = FALSE, $log = FALSE, $headers = [], $delay = FALSE) {
		if(!$this->check_auth()) {
			$this->auth();
		}

		$curl = curl_init();

		curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);
		curl_setopt($curl, CURLOPT_USERAGENT, 'amoCRM-API-client/2.0');
		curl_setopt($curl, CURLOPT_URL, $link);
		if($type == 'CURLOPT_POST') {
			curl_setopt($curl, CURLOPT_POST, TRUE);
			curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($post_data));
		} elseif($type == 'CURLOPT_CUSTOMREQUEST') {
			if(is_array($post_data)) {
				$post_data = json_encode($post_data);
			}
			if(!in_array('Content-Type: application/json', $headers)) {
				$headers[] = 'Content-Type: application/json';
			};
			curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'POST');
			curl_setopt($curl, CURLOPT_POSTFIELDS, $post_data);
		}
		if(!empty($headers)) {
			curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
		}
		curl_setopt($curl, CURLOPT_HEADER, FALSE);

		$cookie_dir = dirname(__FILE__).'/cookies/';
		$cookie_file = $this->settings_key.'_cookie.txt';

		if(!file_exists($cookie_dir) && !is_dir($cookie_dir)) {
			mkdir($cookie_dir);
		}

		curl_setopt($curl, CURLOPT_COOKIEFILE, $cookie_dir.$cookie_file);
		curl_setopt($curl, CURLOPT_COOKIEJAR, $cookie_dir.$cookie_file);
		curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
		curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);

		if($delay) {
			sleep($delay);
		}

		$out = curl_exec($curl);
		$this->count_requests++;

		$code = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
		curl_close($curl);

		if($log === TRUE) {
			$request_str = "Время: ". date("d-m-Y h:i:s", time()) ."; Time(".time().")".PHP_EOL;
			$request_str .= "Ссылка: ".$link.PHP_EOL;
			$request_str .= "Request: ".PHP_EOL.json_encode($post_data).PHP_EOL.PHP_EOL;

			$response_str = "Время: ". date("d-m-Y h:i:s", time()) ."; Time(".time().")".PHP_EOL;
			$response_str .= "Информация: ".$code.PHP_EOL;
			$response_str .= "Response: ".$out.PHP_EOL.PHP_EOL;

			file_put_contents("request.log", $request_str, FILE_APPEND);
			file_put_contents("response.log", $response_str, FILE_APPEND);
		}

		return ["data" => json_decode($out, TRUE), "code" => $code];
	}

	public function __destruct() {
		echo 'Время работы скрипта ' . (time() - $this->starttime) . 'с.'.PHP_EOL;
		echo 'Запросов к API ' . ($this->count_requests) . 'шт.'.PHP_EOL;
		echo 'Пиковое значение затраченной памяти ' . (memory_get_peak_usage(TRUE) / 1024) . 'кб.'.PHP_EOL;

		file_put_contents('moved_data_map.txt', json_encode($this->map));
		echo 'Карта сущностей сохранена в moved_data_map.txt: '.PHP_EOL;

		unset($this);
	}
}
