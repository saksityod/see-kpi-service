<?php

namespace App\Http\Controllers\PMTL;

use App\QuestionaireSection;
use App\QuestionaireDataHeader;
use App\QuestionaireDataDetail;
use App\QuestionaireDataStage;
use App\EmployeeSnapshot;
use App\Questionaire;
use App\Stage;
use App\SystemConfiguration;
use App\Customer;
use App\UsersRoles;

use Auth;
use DB;
use File;
use Validator;
use Excel;
use Mail;
use Config;
use Exception;
use DateTime;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class QuestionaireDataController extends Controller
{

	public function __construct()
	{
		$this->middleware('jwt.auth');
	}

	function format_date($date) {
		if(empty($date)) {
			return "";
		} else {
			$date = strtr($date, '/', '-');
			$date_formated = date('Y-m-d', strtotime($date));
		}

		return $date_formated;
	}

	function trim_text($text) {
		return trim($text);
	}

	function strtolower_text($text) {
		return strtolower($text);
	}

    function get_role() {
    	$role = [];
        $user = UsersRoles::where('userId', Auth::user()->userId)->get();

        if (empty($user)) {
            return $role;
        }

        foreach ($user as $key => $value) {
            array_push($role, $value->roleId);
        }

        return $role;
    }

    function concat_emp_first_last_code($emp) {
    	// $name = explode(' ', str_replace(['(',')'], '', $emp));
    	$name = explode(' ', $emp);
    	if($name[0]) {
    		return $name[0]; //emp_first_name
    	} else if($name[1]) {
    		return $name[1]; //emp_last_name
    	}
    }

    function role_authorize($stage_id) {
        $user = UsersRoles::where('userId', Auth::user()->userId)->get();

        if (empty($user)) {
            return ['errors' => 'user role not found'];
        }

        $role = [];
        foreach ($user as $key => $value) {
            array_push($role, $value->roleId);
        }

        $role_id = implode(',', $role);

        $data = DB::select("
        	SELECT view_comment_flag
        	FROM role_stage_authorize
        	WHERE role_id IN ({$role_id})
        	AND stage_id = '{$stage_id}'
        ");

    	if(empty($data)) {
    		return [
    			'view_comment_flag' => 0
    		];
    	} else {
    		return [
    			'view_comment_flag' => $data[0]->view_comment_flag
    		];
    	}
    }

    function send_email($config, $from_stage_id, $to_stage_id, $data_header_id, $assessor_id, $status) {
    	Config::set('mail.driver',$config->mail_driver);
		Config::set('mail.host',$config->mail_host);
		Config::set('mail.port',$config->mail_port);
		Config::set('mail.encryption',$config->mail_encryption);
		Config::set('mail.username',$config->mail_username);
		Config::set('mail.password',$config->mail_password);
		$from = Config::get('mail.from');

		$workflow_email = DB::table("workflow_stage")->select("send_email_flag")->where("from_stage_id", $from_stage_id)->first();
		if($to_stage_id==3) {
			try {
				$assessor = EmployeeSnapshot::find($assessor_id);
				$role = empty(implode(',', $this->get_role())) ? "''" : implode(',', $this->get_role());
				$emp_snap = DB::select("
					SELECT qdh.data_header_id, 
					CONCAT(es.emp_first_name, ' ', es.emp_last_name) emp_name,
					es.email,
					ifnull(rsa.edit_flag, 0) edit_flag
					FROM questionaire_data_header qdh
					LEFT JOIN employee_snapshot es ON es.emp_snapshot_id = qdh.emp_snapshot_id
					LEFT JOIN role_stage_authorize rsa ON rsa.stage_id = qdh.data_stage_id AND rsa.role_id IN ({$role})
					WHERE qdh.data_header_id = '{$data_header_id}'
					");

				$data = [
					"emp_name" => $emp_snap[0]->emp_name, 
					"status" => $status,
					"web_domain" => $config->web_domain,
					"data_header_id" => $emp_snap[0]->data_header_id,
					"edit_flag" => $emp_snap[0]->edit_flag
				];

				// $to = [$emp_snap[0]->email,$assessor->email,'chokanan@goingjesse.com'];
				$to = ['thawatchai@goingjesse.com','chokanan@goingjesse.com'];

				Mail::send('emails.status_snap', $data, function($message) use ($from, $to)
				{
					$message->from($from['address'], $from['name']);
					$message->to($to)->subject('ระบบได้ทำการประเมิน');
				});
			} catch (Exception $ExceptionError) {
				return ['mail error' => substr($ExceptionError, 0, 255)];
			}
		}
    }

    function get_emp_snapshot_id() {
    	$is_emp = DB::select("
			SELECT emp_snapshot_id
			FROM employee_snapshot
			WHERE emp_code = '".Auth::id()."'
		");

		return $is_emp;
    }

    public function role_authorize_add() {
        $data = [];
        $user = UsersRoles::where('userId', Auth::user()->userId)->get();

        if (empty($user)) {
            return response()->json(['status' => 401, 'add_flag' => 0, 'errors' => 'user role not found']);
        }

        $role = [];
        foreach ($user as $key => $value) {
            array_push($role, $value->roleId);
        }

        $role_id = implode(',', $role);

        $data = DB::select("
        	SELECT add_flag
        	FROM role_stage_authorize
        	WHERE role_id IN ({$role_id})
        	AND stage_id = '1'
        ");

    	if(empty($data)) {
    		return response()->json(['status' => 400, 'add_flag' => 0, 'errors' => 'role not assign to user']);
    	} else {
    		return response()->json(['status' => 200, 'add_flag' => $data[0]->add_flag, 'errors' => []]);
    	}
    }

	public function auto_emp(Request $request) {
		$emp_name = $this->concat_emp_first_last_code($request->emp_name);
		$request->start_date = $this->format_date($request->start_date);
		$request->end_date = $this->format_date($request->end_date);

		$items = DB::select("
			SELECT es.emp_snapshot_id, 
					CONCAT(es.emp_first_name, ' ', es.emp_last_name) emp_name,
					p.position_code,
					es.emp_code
			FROM employee_snapshot es
			LEFT JOIN position p ON p.position_id = es.position_id
			INNER JOIN questionaire_data_header qdh ON qdh.emp_snapshot_id = es.emp_snapshot_id
			WHERE (
				es.emp_first_name LIKE '%{$emp_name}%'
				OR es.emp_last_name LIKE '%{$emp_name}%'
				OR p.position_code LIKE '%{$emp_name}%'
			)
			AND qdh.questionaire_date BETWEEN '{$request->start_date}' AND '{$request->end_date}'
			GROUP BY es.emp_snapshot_id
			LIMIT 10
		");
		return response()->json($items);
	}

	public function auto_emp2(Request $request) {
		$emp_name = $this->concat_emp_first_last_code($request->emp_name);
		$request->date = $this->format_date($request->date);

		$items = DB::select("
			SELECT es.emp_snapshot_id, 
					CONCAT(es.emp_first_name, ' ', es.emp_last_name) emp_name, 
					es.distributor_name,
					CONCAT(chief.emp_first_name, ' ', chief.emp_last_name) chief_emp_name,
					p.position_code,
					es.emp_code
			FROM employee_snapshot es
			LEFT JOIN position p ON p.position_id = es.position_id
			LEFT JOIN employee_snapshot chief ON chief.emp_code = es.chief_emp_code
			WHERE (
				es.emp_first_name LIKE '%{$emp_name}%'
				OR es.emp_last_name LIKE '%{$emp_name}%'
				OR p.position_code LIKE '%{$emp_name}%'
			) AND es.emp_snapshot_id NOT IN (
				SELECT emp_snapshot_id
				FROM questionaire_data_header
				WHERE questionaire_date = '{$request->date}'
				AND questionaire_id = '{$request->questionaire_id}'
			) LIMIT 10
		");
		return response()->json($items);
	}

	public function auto_emp_permission(Request $request) {
		$emp_name = $this->concat_emp_first_last_code($request->emp_name);
		$emp_code = Auth::id();

		$items = DB::select("
			SELECT es.emp_snapshot_id, 
					CONCAT(es.emp_first_name, ' ', es.emp_last_name) emp_name
			FROM employee_snapshot es
			LEFT JOIN position p ON p.position_id = es.position_id
			WHERE (
				es.emp_first_name LIKE '%{$emp_name}%'
				OR es.emp_last_name LIKE '%{$emp_name}%'
				OR p.position_code LIKE '%{$emp_name}%'
			) AND (
				es.chief_emp_code = '{$emp_code}' or es.emp_code = '{$emp_code}'
			) LIMIT 10
		");
		return response()->json($items);
	}

	public function auto_store(Request $request) {
		$position_code = empty($request->position_code) ? "" : "AND cp.position_code = '{$request->position_code}'";

		$items = DB::select("
			SELECT c.customer_id, c.customer_name
			FROM customer c
			LEFT JOIN customer_position cp ON cp.customer_id = c.customer_id
			WHERE c.customer_name LIKE '%{$request->customer_name}%'
			AND c.customer_id NOT IN (
				SELECT customer_id
				FROM questionaire_data_detail
				WHERE data_header_id = '{$request->data_header_id}'
			)
			".$position_code."
			LIMIT 10
			");
		return response()->json($items);
	}

	public function list_questionaire(Request $request) {
		$items = DB::select("
			SELECT questionaire_id, questionaire_name
			FROM questionaire
			ORDER BY questionaire_id
			");
		return response()->json($items);
	}

	public function evaluated_retailer_list(Request $request) {
		$request->date = $this->format_date($request->date);
		$items = DB::select("
			SELECT qdh.data_header_id, qdd.customer_id, c.customer_name, SUM(qdd.score) score, qdd.section_id 
			FROM questionaire_data_detail qdd
			LEFT JOIN customer c ON c.customer_id = qdd.customer_id
			LEFT JOIN customer_position cp ON cp.customer_id = c.customer_id
			LEFT JOIN questionaire_data_header qdh ON qdh.data_header_id = qdd.data_header_id 
			WHERE qdd.section_id = '{$request->section_id}'
			AND cp.position_code = '{$request->position_code}'
			AND qdh.data_header_id = '{$request->data_header_id}'
			AND qdh.questionaire_date = (
				SELECT MAX(qdhh.questionaire_date) questionaire_date
				FROM questionaire_data_header qdhh
				WHERE qdhh.data_header_id = qdh.data_header_id
				AND qdhh.questionaire_date BETWEEN '' AND '{$request->date}'
			)
			GROUP BY qdd.customer_id
		");
		return response()->json($items);
	}

	public function evaluated_retailer_list_edit(Request $request) {
		try {
			$items = QuestionaireDataHeader::findOrFail($request->data_header_id);
		} catch (ModelNotFoundException $e) {
			return response()->json(['status' => 404, 'data' => 'QuestionaireDataHeader not found.']);
		}

		try {
			$customer = Customer::select('customer_id', 'customer_name')->where('customer_id', $request->customer_id)->firstOrFail();
		} catch (ModelNotFoundException $e) {
			return response()->json(['status' => 404, 'data' => 'Customer not found.']);
		}

		$sub_items = DB::select("
			SELECT qdd.section_id, qs.section_name, qs.is_cust_search, qs.is_show_report, qs.report_url
			FROM questionaire_data_detail qdd
			INNER JOIN questionaire_section qs ON qs.section_id = qdd.section_id
			WHERE qdd.data_header_id = '{$request->data_header_id}'
			AND qdd.customer_id = '{$request->customer_id}'
			GROUP BY qdd.section_id
		");

		foreach ($sub_items as $key => $v_qdd) {
			$sub_items[$key]->sub_section = DB::select("
				SELECT q.question_id, 
				q.answer_type_id, 
				q.parent_question_id, 
				q.question_name, 
				q.pass_score
				FROM question q
				INNER JOIN answer_type at ON at.answer_type_id = q.answer_type_id
				WHERE q.section_id = {$v_qdd->section_id}
				AND q.parent_question_id IS NULL
			");

			foreach ($sub_items[$key]->sub_section as $key2 => $anv) {
				$sub_items[$key]->sub_section[$key2]->answer =  DB::select("
					SELECT *
					FROM (
						SELECT
							qdd.data_detail_id,
							an.answer_id,
							an.row_name,
							an.answer_name,
							an.score,
							an.is_not_applicable,
							qdd.desc_answer,
							qdd.full_score,
							IF ( an.answer_id = qdd.answer_id, 1, 0) is_check
						FROM questionaire_data_detail qdd
						INNER JOIN answer an ON an.question_id = qdd.question_id
						AND qdd.question_id = {$anv->question_id}
						AND qdd.data_header_id = '{$request->data_header_id}'
						AND qdd.customer_id = '{$request->customer_id}'
						GROUP BY is_check, an.answer_id
						ORDER BY is_check DESC, an.answer_id
					)d1
					GROUP BY answer_id
				");
			}

			foreach ($sub_items[$key]->sub_section as $key2 => $anv) {
				$sub_items[$key]->sub_section[$key2]->question =  DB::select("
					SELECT q.question_id, 
							q.answer_type_id, 
							at.is_show_comment, 
							q.parent_question_id, 
							q.question_name
					FROM question q
					INNER JOIN answer_type at ON at.answer_type_id = q.answer_type_id
					WHERE q.parent_question_id = {$anv->question_id}
					");

				foreach ($sub_items[$key]->sub_section[$key2]->question as $key3 => $ssq) {
					$sub_items[$key]->sub_section[$key2]->question[$key3]->answer =  DB::select("
						SELECT *
						FROM (
							SELECT
							qdd.data_detail_id,
							an.answer_id,
							an.row_name,
							an.answer_name,
							an.score,
							an.is_not_applicable,
							qdd.desc_answer,
							qdd.full_score,
							IF ( an.answer_id = qdd.answer_id, 1, 0) is_check
							FROM questionaire_data_detail qdd
							INNER JOIN answer an ON an.question_id = qdd.question_id
							AND qdd.question_id = {$ssq->question_id}
							AND qdd.data_header_id = '{$request->data_header_id}'
							AND qdd.customer_id = '{$request->customer_id}'
							GROUP BY is_check, an.answer_id
							ORDER BY is_check DESC, an.answer_id
						)d1
						GROUP BY answer_id
						");
				}
			}
		}

		return response()->json(['customer'=> $customer, 'data' => $sub_items[0]]);
	}

	public function index(Request $request) {
		$request->start_date = $this->format_date($request->start_date);
		$request->end_date = $this->format_date($request->end_date);

		$between_date = "
			AND qdh.questionaire_date = (
				SELECT MAX(qdhh.questionaire_date) questionaire_date
				FROM questionaire_data_header qdhh
				WHERE qdhh.emp_snapshot_id = qdh.emp_snapshot_id
		";

		if(empty($request->start_date) && empty($request->end_date)) {
            $between_date .= " )";
        } else if(empty($request->start_date)) {
            $between_date .= " AND qdhh.questionaire_date BETWEEN '' AND '{$request->end_date}' )";
        } else if(empty($request->end_date)) {
            $between_date .= " AND qdhh.questionaire_date >= '{$request->start_date}' )";
        } else {
            $between_date .= " AND qdhh.questionaire_date BETWEEN '{$request->start_date}' AND '{$request->end_date}' )";
        }

		$emp_snapshot_id = empty($request->emp_snapshot_id) ? "" : "AND qdh.emp_snapshot_id = '{$request->emp_snapshot_id}'";

		$items = DB::select("
			SELECT qdh.data_header_id
			FROM questionaire_data_header qdh
			LEFT JOIN questionaire qn ON qn.questionaire_id = qdh.questionaire_id
			WHERE qdh.questionaire_id = '{$request->questionaire_id}'
			".$between_date."
			".$emp_snapshot_id."
			ORDER BY qdh.questionaire_date
		");

		$header_array_id = [];
		foreach ($items as $key => $value) {
            array_push($header_array_id, $value->data_header_id);
        }

        $header_id = empty(implode(',', $header_array_id)) ? "''" : implode(',', $header_array_id);
		$role = empty(implode(',', $this->get_role())) ? "''" : implode(',', $this->get_role());

		$all_emp = DB::select("
			SELECT sum(b.is_all_employee) count_no
			from employee a
			left outer join appraisal_level b
			on a.level_id = b.level_id
			where emp_code = ?
			", array(Auth::id()));

		if ($all_emp[0]->count_no > 0) {
			$assessor = "";
		} else {
			$assessor_id = $this->get_emp_snapshot_id();
			$assessor_idd = $assessor_id[0]->emp_snapshot_id;
			$assessor = "AND qdh.assessor_id = '{$assessor_idd}'";
		}

		$items = DB::select("
			SELECT qdh.data_header_id, DATE_FORMAT(qdh.questionaire_date,'%d/%m/%Y') questionaire_date 
			FROM questionaire_data_header qdh
			LEFT JOIN questionaire qn ON qn.questionaire_id = qdh.questionaire_id
			WHERE qdh.questionaire_id = '{$request->questionaire_id}'
			".$assessor."
			".$between_date."
			".$emp_snapshot_id."
			GROUP BY qdh.questionaire_date
			ORDER BY qdh.questionaire_date
		");

		foreach ($items as $key => $value) {

			$items[$key]->data = DB::select("
				SELECT qdh.data_header_id, 
						p.position_code, 
						CONCAT(es.emp_first_name, ' ', es.emp_last_name) emp_name,
						#ifnull(rsa.add_flag, 0) add_flag, 
						ifnull(rsa.edit_flag, 0) edit_flag, 
						ifnull(rsa.delete_flag, 0) delete_flag, 
						ifnull(rsa.view_flag, 0) view_flag
				FROM questionaire_data_header qdh
				LEFT JOIN employee_snapshot es ON es.emp_snapshot_id = qdh.emp_snapshot_id
				LEFT JOIN position p ON p.position_id = es.position_id
				LEFT JOIN questionaire qn ON qn.questionaire_id = qdh.questionaire_id
				LEFT JOIN role_stage_authorize rsa ON rsa.stage_id = qdh.data_stage_id AND rsa.role_id IN ({$role})
				WHERE qdh.questionaire_date = '{$this->format_date($value->questionaire_date)}'
				AND qdh.data_header_id IN ({$header_id})
				GROUP BY qdh.data_header_id
				ORDER BY qdh.data_stage_id DESC
				");
		}

		return response()->json($items);
	}

	public function assign_template(Request $request) {

		if(!empty($request->data_header_id)) {
			try {
				$data_header = QuestionaireDataHeader::findOrFail($request->data_header_id);
			} catch (ModelNotFoundException $e) {
				return response()->json(['status' => 404, 'data' => 'QuestionaireDataHeader not found.']);
			}

			$head = DB::select("
				SELECT DATE_FORMAT(qdh.questionaire_date,'%d/%m/%Y') questionaire_date,
						es.emp_snapshot_id,
						CONCAT(es.emp_first_name, ' ', es.emp_last_name) emp_name, 
						es.distributor_name,
						CONCAT(chief.emp_first_name, ' ', chief.emp_last_name) chief_emp_name,
						p.position_code,
						qt.questionaire_type_id
				FROM questionaire_data_header qdh
				LEFT JOIN employee_snapshot es ON es.emp_snapshot_id = qdh.emp_snapshot_id
				LEFT JOIN employee_snapshot chief ON chief.emp_code = es.chief_emp_code
				LEFT JOIN position p ON p.position_id = es.position_id
				INNER JOIN questionaire q ON q.questionaire_id = qdh.questionaire_id
				INNER JOIN questionaire_type qt ON qt.questionaire_type_id = q.questionaire_type_id
				WHERE qdh.data_header_id = {$request->data_header_id}
			");

			$head = $head[0];

			$sub_items = DB::select("
				SELECT qdd.section_id, qs.section_name, qs.is_cust_search, qs.is_show_report, qs.report_url
				FROM questionaire_data_detail qdd
				INNER JOIN questionaire_section qs ON qs.section_id = qdd.section_id
				WHERE qdd.data_header_id = '{$request->data_header_id}'
				GROUP BY qdd.section_id
			");

			foreach ($sub_items as $key => $v_qdd) {
				$sub_items[$key]->sub_section = DB::select("
					SELECT q.question_id, 
							q.answer_type_id, 
							q.parent_question_id, 
							q.question_name, 
							q.pass_score
					FROM question q
					INNER JOIN answer_type at ON at.answer_type_id = q.answer_type_id
					WHERE q.section_id = {$v_qdd->section_id}
					AND q.parent_question_id IS NULL
				");

				if($v_qdd->is_cust_search==0) {
					foreach ($sub_items[$key]->sub_section as $key2 => $anv) {
						$sub_items[$key]->sub_section[$key2]->answer =  DB::select("
							SELECT *
							FROM (
								SELECT
									qdd.data_detail_id,
									an.answer_id,
									an.row_name,
									an.answer_name,
									an.score,
									an.is_not_applicable,
									qdd.desc_answer,
									qdd.full_score,
								IF (
									an.answer_id = qdd.answer_id,
									1,
									0
								) is_check
								FROM
									questionaire_data_detail qdd
								INNER JOIN answer an ON an.question_id = qdd.question_id
								AND qdd.question_id = {$anv->question_id}
								AND qdd.data_header_id = '{$request->data_header_id}'
								GROUP BY is_check, an.answer_id
								ORDER BY is_check DESC, an.answer_id
							)d1
							GROUP BY answer_id
						");
					}

					foreach ($sub_items[$key]->sub_section as $key2 => $anv) {
						$sub_items[$key]->sub_section[$key2]->question =  DB::select("
							SELECT q.question_id, 
									q.answer_type_id, 
									at.is_show_comment, 
									q.parent_question_id, 
									q.question_name
							FROM question q
							INNER JOIN answer_type at ON at.answer_type_id = q.answer_type_id
							WHERE q.parent_question_id = {$anv->question_id}
							");

						foreach ($sub_items[$key]->sub_section[$key2]->question as $key3 => $ssq) {
							$sub_items[$key]->sub_section[$key2]->question[$key3]->answer =  DB::select("
								SELECT *
								FROM (
									SELECT
										qdd.data_detail_id,
										an.answer_id,
										an.row_name,
										an.answer_name,
										an.score,
										an.is_not_applicable,
										qdd.desc_answer,
										qdd.full_score,
									IF (
										an.answer_id = qdd.answer_id,
										1,
										0
									) is_check
									FROM
										questionaire_data_detail qdd
									INNER JOIN answer an ON an.question_id = qdd.question_id
									AND qdd.question_id = {$ssq->question_id}
									AND qdd.data_header_id = '{$request->data_header_id}'
									GROUP BY is_check, an.answer_id
									ORDER BY is_check DESC, an.answer_id
								)d1
								GROUP BY answer_id
							");
						}
					}
				} else {
					foreach ($sub_items[$key]->sub_section as $key2 => $anv) {
						$sub_items[$key]->sub_section[$key2]->answer =  DB::select("
							SELECT answer_id, row_name, answer_name, score, is_not_applicable, '' desc_answer
							FROM answer
							WHERE question_id = {$anv->question_id}
							ORDER BY seq_no ASC
						");
					}

					foreach ($sub_items[$key]->sub_section as $key2 => $anv) {
						$sub_items[$key]->sub_section[$key2]->question =  DB::select("
							SELECT q.question_id, 
									q.answer_type_id, 
									at.is_show_comment, 
									q.parent_question_id, 
									q.question_name
							FROM question q
							INNER JOIN answer_type at ON at.answer_type_id = q.answer_type_id
							WHERE q.parent_question_id = {$anv->question_id}
							");

						foreach ($sub_items[$key]->sub_section[$key2]->question as $key3 => $ssq) {
							$sub_items[$key]->sub_section[$key2]->question[$key3]->answer =  DB::select("
								SELECT answer_id, row_name, answer_name, score, is_not_applicable, '' desc_answer
								FROM answer
								WHERE question_id = {$ssq->question_id}
								ORDER BY seq_no ASC
							");
						}
					}
				}
			}

			$stage = DB::select("
				SELECT CONCAT(es.emp_first_name, ' ',es.emp_last_name) emp_name, CONCAT(chief.emp_first_name, ' ',chief.emp_last_name) chief_emp_name, qds.remark, qds.created_dttm, qds.created_by, s.stage_name from_action, st.stage_name to_action
				FROM questionaire_data_stage qds
				LEFT JOIN employee_snapshot es ON es.emp_snapshot_id = qds.to_emp_snapshot_id
				LEFT JOIN employee_snapshot chief ON chief.emp_snapshot_id = qds.from_emp_snapshot_id
				LEFT JOIN stage s ON s.stage_id = qds.from_stage_id
				LEFT JOIN stage st ON st.stage_id = qds.to_stage_id
				WHERE data_header_id = '{$request->data_header_id}'
			");

			$current_stage = DB::select("
                SELECT stage_id, stage_name
                from stage
                where stage_id = '{$data_header->data_stage_id}'
            ");

 			$role = $this->role_authorize($data_header->data_stage_id);

		} else if(!empty($request->questionaire_id)) {
			try {
				QuestionaireSection::where('questionaire_id', '=', $request->questionaire_id)->firstOrFail();
			} catch (ModelNotFoundException $e) {
				return response()->json(['status' => 404, 'data' => 'QuestionaireSection not found.']);
			}

			$head = DB::select("
				SELECT qt.questionaire_type_id
				FROM questionaire_type qt, questionaire q
				WHERE q.questionaire_type_id = qt.questionaire_type_id
				AND q.questionaire_id = '{$request->questionaire_id}'
			");

			$head = $head[0];

			$sub_items = DB::select("
				SELECT section_id, section_name, is_cust_search, is_show_report, report_url
				FROM questionaire_section
				WHERE questionaire_id = '{$request->questionaire_id}'
			");

			foreach ($sub_items as $key => $qsv) {
				$sub_items[$key]->sub_section =  DB::select("
					SELECT q.question_id, q.answer_type_id, q.parent_question_id, q.question_name, q.pass_score
					FROM question q
					LEFT JOIN answer_type at ON at.answer_type_id = q.answer_type_id
					WHERE q.section_id = {$qsv->section_id}
					AND q.parent_question_id IS NULL
					ORDER BY q.seq_no ASC
					");

				foreach ($sub_items[$key]->sub_section as $key2 => $anv) {
					$sub_items[$key]->sub_section[$key2]->answer =  DB::select("
							SELECT 
								ans.question_id,
								ans.answer_id, 
								ans.row_name, 
								ans.answer_name, 
								ans.score, 
								ans.is_not_applicable, 
								'' desc_answer,
								1 full_score,
								IF(
									is_applicable > 0 AND ans.is_not_applicable = 1, 
									1, 
									IF(
										is_applicable > 0, 
										0, 
										IF(
											ans.score = 0,
											1,
											0
										)
									)
								) is_check
							FROM answer ans
							LEFT OUTER JOIN (
								SELECT question_id, SUM(is_not_applicable) is_applicable
								FROM answer
								GROUP BY question_id
							)cab ON cab.question_id = ans.question_id
							WHERE ans.question_id = {$anv->question_id}
							ORDER BY ans.seq_no ASC
						");
				}

				foreach ($sub_items[$key]->sub_section as $key2 => $anv) {
					$sub_items[$key]->sub_section[$key2]->question =  DB::select("
						SELECT q.question_id, q.answer_type_id, at.is_show_comment, q.parent_question_id, q.question_name
						FROM question q
						LEFT JOIN answer_type at ON at.answer_type_id = q.answer_type_id
						WHERE q.parent_question_id = {$anv->question_id}
						ORDER BY q.seq_no ASC
						");

					foreach ($sub_items[$key]->sub_section[$key2]->question as $key3 => $ssq) {
						$sub_items[$key]->sub_section[$key2]->question[$key3]->answer =  DB::select("
							SELECT 
								ans.question_id,
								ans.answer_id, 
								ans.row_name, 
								ans.answer_name, 
								ans.score, 
								ans.is_not_applicable, 
								'' desc_answer,
								1 full_score,
								IF(
									is_applicable > 0 AND ans.is_not_applicable = 1, 
									1, 
									IF(
										is_applicable > 0, 
										0, 
										IF(
											ans.score = 0,
											1,
											0
										)
									)
								) is_check
							FROM answer ans
							LEFT OUTER JOIN (
								SELECT question_id, SUM(is_not_applicable) is_applicable
								FROM answer
								GROUP BY question_id
							)cab ON cab.question_id = ans.question_id
							WHERE ans.question_id = {$ssq->question_id}
							ORDER BY ans.seq_no ASC
							");
					}
				}
			}

			$current_stage = DB::select("
                SELECT stage_id, stage_name
                from stage
                where stage_id = '1'
            ");

			$stage = [];

			$role = (object)[];

		} else {
			return response()->json(['status' => 404, 'data' => 'Parameter not found.']);
		}

        $workflow_stage = DB::select("
            select to_stage_id, status
            from workflow_stage
            where from_stage_id = ?
        ",array($current_stage[0]->stage_id));
        
        if (empty($workflow_stage)) {
            return response()->json([
            	'head' => $head, 
            	'data' => $sub_items, 
            	'stage' => $stage, 
            	'current_stage' => $current_stage, 
            	'to_stage' => [],
            	'role' => $role
            ]);
        }
        
        $actions = DB::select("
            SELECT s.stage_id, s.stage_name, s.stage_name as status
            FROM stage s
            WHERE s.stage_id IN ({$workflow_stage[0]->to_stage_id})
            ORDER BY s.stage_id
        ");

		return response()->json([
			'head' => $head, 
			'data' => $sub_items, 
			'stage' => $stage, 
			'current_stage' => $current_stage, 
			'to_stage' => $actions,
			'role' => $role
		]);
	}

	public function store(Request $request) {
		try {
			$config = SystemConfiguration::firstOrFail();
		} catch (ModelNotFoundException $e) {
			return response()->json(['status' => 404, 'data' => 'System Configuration not found in DB.']);
		}

		$is_emp = $this->get_emp_snapshot_id();

		if(empty($is_emp)) {
			return response()->json(['status' => 404, 'data' => 'Assessor not found in Employee Snapshot.']);
		}
		
		DB::beginTransaction();
		$errors = [];
		$errors_validator = [];
		$validator = Validator::make([
			'questionaire_id' => $request->questionaire_id,
			'questionaire_date' => $request->questionaire_date,
			'emp_snapshot_id' => $request->emp_snapshot_id,
			'total_score' => $request->total_score
		], [
			'questionaire_id' => 'required|integer',
			'questionaire_date' => 'required|date_format:d/m/Y',
			'emp_snapshot_id' => 'required|integer',
			'total_score' => 'required|between:0,99.99'
		]);

		if($validator->fails()) {
			$errors_validator[] = $validator->errors();
		}

		if(!empty($request['detail'])) {
			foreach ($request['detail'] as $d) {
				$validator_detail = Validator::make([
					'section_id' => $d['section_id'],
					'customer_id' => $d['customer_id'],
					'question_id' => $d['question_id'],
					'answer_id' => $d['answer_id'],
					'score' => $d['score'],
					'full_score' => $d['full_score'],
					'is_not_applicable' => $d['is_not_applicable']
				], [
					'section_id' => 'required|integer',
					'customer_id' => 'integer',
					'question_id' => 'required|integer',
					'answer_id' => 'required|integer',
					'score' => 'required|between:0,99.99',
					'full_score' => 'required|between:0,99.99',
					'is_not_applicable' => 'required|integer'
				]);

				if($validator_detail->fails()) {
					$errors_validator[] = $validator_detail->errors();
				}
			}
		}

		$validator_stage = Validator::make([
			'from_stage_id' => $request->stage['from_stage_id'],
			'to_stage_id' => $request->stage['to_stage_id']
		], [
			'from_stage_id' => 'required|integer',
			'to_stage_id' => 'required|integer'
		]);

		if($validator_stage->fails()) {
			$errors_validator[] = $validator_stage->errors();
		}

		if(!empty($errors_validator)) {
			return response()->json(['status' => 400, 'errors' => $errors_validator]);
		}

		$h = new QuestionaireDataHeader;
		$h->questionaire_id = $request->questionaire_id;
		$h->questionaire_date = $this->format_date($request->questionaire_date);
		$h->emp_snapshot_id = $request->emp_snapshot_id;
		$h->assessor_id = $is_emp[0]->emp_snapshot_id;
		$h->pass_score = Questionaire::find($request->questionaire_id)->pass_score;
		$h->total_score = $request->total_score;
		$h->created_by = Auth::id();
		$h->updated_by = Auth::id();
		try {
			$h->save();
			$s = new QuestionaireDataStage;
			$s->data_header_id = $h->data_header_id;
			$s->from_emp_snapshot_id = $is_emp[0]->emp_snapshot_id;
			$s->from_stage_id = $request->stage['from_stage_id'];
			$s->to_emp_snapshot_id = $request->emp_snapshot_id;
			$s->to_stage_id = $request->stage['to_stage_id'];
			$s->status = Stage::find($request->stage['to_stage_id'])->stage_name;
			$s->remark = $request->stage['remark'];
			$s->created_by = Auth::id();
			try {
				$s->save();
				QuestionaireDataHeader::where('data_header_id', $h->data_header_id)->update([
					'data_stage_id' => $request->stage['to_stage_id'], 
					'updated_by' => Auth::id()
				]);
			} catch (Exception $e) {
				$errors[] = ['QuestionaireDataStage or QuestionaireDataHeader' => substr($e, 0, 255)];
			}

			if(!empty($request['detail'])) {
				foreach ($request['detail'] as $keyD => $d) {
					$dt = new QuestionaireDataDetail;
					$dt->data_header_id = $h->data_header_id;
					$dt->section_id = $d['section_id'];
					$dt->customer_id = $d['customer_id'];
					$dt->question_id = $d['question_id'];
					$dt->answer_id = $d['answer_id'];
					$dt->score = $d['score'];
					$dt->full_score = $d['full_score'];
					$dt->is_not_applicable = $d['is_not_applicable'];
					$dt->desc_answer = $d['desc_answer'];
					$dt->created_by = Auth::id();
					$dt->updated_by = Auth::id();
					try {
						$dt->save();
					} catch (Exception $e) {
						$errors[] = ['QuestionaireDataDetail' => substr($e, 0, 255)];
					}
				}
			}
		} catch (Exception $e) {
			$errors[] = ['QuestionaireDataHeader' => substr($e, 0, 255)];
		}

		empty($errors) ? DB::commit() : DB::rollback();
		empty($errors) ? $status = 200 : $status = 400;

		if($status==200) {
			$errors[] = $this->send_email($config, $request->stage['from_stage_id'], $request->stage['to_stage_id'], $h->data_header_id, $h->assessor_id, $s->status);
		}

        return response()->json(['status' => $status, 'errors' => $errors]);

	}

	public function update(Request $request) {
		try {
			$config = SystemConfiguration::firstOrFail();
		} catch (ModelNotFoundException $e) {
			return response()->json(['status' => 404, 'data' => 'System Configuration not found in DB.']);
		}

		try {
			$h = QuestionaireDataHeader::findOrFail($request->data_header_id);
		} catch (ModelNotFoundException $e) {
			return response()->json(['status' => 404, 'data' => 'QuestionaireDataHeader not found.']);
		}

		$is_emp = $this->get_emp_snapshot_id();

		if(empty($is_emp)) {
			return response()->json(['status' => 404, 'data' => 'Assessor not found in Employee Snapshot.']);
		}
		
		DB::beginTransaction();
		$errors = [];
		$errors_validator = [];
		$validator = Validator::make([
			'questionaire_id' => $request->questionaire_id,
			'questionaire_date' => $request->questionaire_date,
			'emp_snapshot_id' => $request->emp_snapshot_id,
			'total_score' => $request->total_score
		], [
			'questionaire_id' => 'required|integer',
			'questionaire_date' => 'required|date_format:d/m/Y',
			'emp_snapshot_id' => 'required|integer',
			'total_score' => 'required|between:0,99.99'
		]);

		if($validator->fails()) {
			$errors_validator[] = $validator->errors();
		}

		if(!empty($request['detail'])) {
			foreach ($request['detail'] as $d) {
				$validator_detail = Validator::make([
					'section_id' => $d['section_id'],
					'customer_id' => $d['customer_id'],
					'question_id' => $d['question_id'],
					'answer_id' => $d['answer_id'],
					'score' => $d['score'],
					'full_score' => $d['full_score'],
					'is_not_applicable' => $d['is_not_applicable']
				], [
					'section_id' => 'required|integer',
					'customer_id' => 'integer',
					'question_id' => 'required|integer',
					'answer_id' => 'required|integer',
					'score' => 'required|between:0,99.99',
					'full_score' => 'required|between:0,99.99',
					'is_not_applicable' => 'required|integer'
				]);

				if($validator_detail->fails()) {
					$errors_validator[] = $validator_detail->errors();
				}
			}
		}

		$validator_stage = Validator::make([
			'from_stage_id' => $request->stage['from_stage_id'],
			'to_stage_id' => $request->stage['to_stage_id']
		], [
			'from_stage_id' => 'required|integer',
			'to_stage_id' => 'required|integer'
		]);

		if($validator_stage->fails()) {
			$errors_validator[] = $validator_stage->errors();
		}

		if(!empty($errors_validator)) {
			return response()->json(['status' => 400, 'errors' => $errors_validator]);
		}

		$h->questionaire_id = $request->questionaire_id;
		$h->questionaire_date = $this->format_date($request->questionaire_date);
		$h->emp_snapshot_id = $request->emp_snapshot_id;
		$h->assessor_id = $is_emp[0]->emp_snapshot_id;
		$h->pass_score = Questionaire::find($request->questionaire_id)->pass_score;
		$h->total_score = $request->total_score;
		$h->updated_by = Auth::id();
		try {
			$h->save();
			$s = new QuestionaireDataStage;
			$s->data_header_id = $h->data_header_id;
			$s->from_emp_snapshot_id = $is_emp[0]->emp_snapshot_id;
			$s->from_stage_id = $request->stage['from_stage_id'];
			$s->to_emp_snapshot_id = $request->emp_snapshot_id;
			$s->to_stage_id = $request->stage['to_stage_id'];
			$s->status = Stage::find($request->stage['to_stage_id'])->stage_name;
			$s->remark = $request->stage['remark'];
			$s->created_by = Auth::id();
			try {
				$s->save();
				QuestionaireDataHeader::where('data_header_id', $h->data_header_id)->update([
					'data_stage_id' => $request->stage['to_stage_id'], 
					'updated_by' => Auth::id()
				]);
			} catch (Exception $e) {
				$errors[] = ['QuestionaireDataStage or QuestionaireDataHeader' => substr($e, 0, 255)];
			}

			if(!empty($request['detail'])) {
				foreach ($request['detail'] as $keyD => $d) {
					if(empty($d['data_detail_id'])) {
						$dt = new QuestionaireDataDetail;
						$dt->data_header_id = $h->data_header_id;
						$dt->section_id = $d['section_id'];
						$dt->customer_id = $d['customer_id'];
						$dt->question_id = $d['question_id'];
						$dt->answer_id = $d['answer_id'];
						$dt->score = $d['score'];
						$dt->full_score = $d['full_score'];
						$dt->is_not_applicable = $d['is_not_applicable'];
						$dt->desc_answer = $d['desc_answer'];
						$dt->created_by = Auth::id();
						$dt->updated_by = Auth::id();
					} else {
						$dt = QuestionaireDataDetail::find($d['data_detail_id']);
						$dt->data_header_id = $h->data_header_id;
						$dt->section_id = $d['section_id'];
						$dt->customer_id = $d['customer_id'];
						$dt->question_id = $d['question_id'];
						$dt->answer_id = $d['answer_id'];
						$dt->score = $d['score'];
						$dt->full_score = $d['full_score'];
						$dt->is_not_applicable = $d['is_not_applicable'];
						$dt->desc_answer = $d['desc_answer'];
						$dt->updated_by = Auth::id();
					}
					try {
						$dt->save();
					} catch (Exception $e) {
						$errors[] = ['QuestionaireDataDetail' => substr($e, 0, 255)];
					}
				}
			}
		} catch (Exception $e) {
			$errors[] = ['QuestionaireDataHeader' => substr($e, 0, 255)];
		}

		empty($errors) ? DB::commit() : DB::rollback();
		empty($errors) ? $status = 200 : $status = 400;

		if($status==200) {
			$errors[] = $this->send_email($config, $request->stage['from_stage_id'], $request->stage['to_stage_id'], $h->data_header_id, $h->assessor_id, $s->status);
		}

        return response()->json(['status' => $status, 'errors' => $errors]);
	}

	public function destroy($data_header_id) {
		try {
			$item = QuestionaireDataHeader::findOrFail($data_header_id);
		} catch (ModelNotFoundException $e) {
			return response()->json(['status' => 404, 'data' => 'QuestionaireDataHeader not found.']);
		}

		try {
			DB::table('questionaire_data_stage')->where('data_header_id', '=', $data_header_id)->delete();
			QuestionaireDataDetail::where('data_header_id',$data_header_id)->delete();
			$item->delete();
		} catch (Exception $e) {
			if ($e->errorInfo[1] == 1451) {
				return response()->json(['status' => 400, 'data' => 'Cannot delete because this QuestionaireDataHeader is in use.']);
			} else {
				return response()->json($e->errorInfo);
			}
		}

		return response()->json(['status' => 200]);

	}

	public function destroy_evaluated_retailer_list(Request $request) {
		try {
			QuestionaireDataDetail::where('data_header_id', $request->data_header_id)->firstOrFail();
		} catch (ModelNotFoundException $e) {
			return response()->json(['status' => 404, 'data' => 'QuestionaireDataDetail not found.']);
		}

		try {
			Customer::where('customer_id', $request->customer_id)->firstOrFail();
		} catch (ModelNotFoundException $e) {
			return response()->json(['status' => 404, 'data' => 'Customer not found.']);
		}

		try {
			DB::table('questionaire_data_detail')->where('data_header_id', '=', $request->data_header_id)->where('customer_id', '=', $request->customer_id)->delete();
		} catch (Exception $e) {
			if ($e->errorInfo[1] == 1451) {
				return response()->json(['status' => 400, 'data' => 'Cannot delete because this QuestionaireDataDetail is in use.']);
			} else {
				return response()->json($e->errorInfo);
			}
		}

		return response()->json(['status' => 200]);
	}
}
