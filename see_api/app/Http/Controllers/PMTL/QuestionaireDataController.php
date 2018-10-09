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
use App\QuestionaireType;
use App\AppraisalLevel;
use App\Question;

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

    function concat_emp_first_last_code($emp) {
    	// $name = explode(' ', str_replace(['(',')'], '', $emp));
    	$name = explode(' ', $emp);
    	if($name[0]) {
    		return $name[0]; //emp_first_name
    	} else if($name[1]) {
    		return $name[1]; //emp_last_name
    	}
    }

    function check_permission($stage_id, $data_header_id) {
    	$is_all = $this->all_emp();
    	if($is_all[0]->count_no == 0) {
	        $assessor = $this->get_emp_snapshot();
	        $emp_code = Auth::id();
	    	$data = DB::select("
	        	SELECT rsa.view_comment_flag
				FROM level_stage_authorize rsa
				LEFT JOIN questionaire_data_header qdh ON qdh.data_stage_id = rsa.stage_id
				INNER JOIN employee_snapshot es ON es.emp_snapshot_id = qdh.emp_snapshot_id
				WHERE rsa.level_id = '{$assessor->level_id}'
	        	AND rsa.stage_id = '{$stage_id}'
	        	AND qdh.data_header_id = '{$data_header_id}'
	        	AND (es.emp_code = '{$emp_code}' OR qdh.assessor_id = '{$assessor->emp_snapshot_id}' )
	        ");

	    	if(empty($data)) {
	    		exit(json_encode(['status' => 401, 'data' => 'Permission Fail']));
	    	}
    	}
    }

    function send_email($config, $to_stage_id, $data_header_id, $assessor_id, $status) {
    	Config::set('mail.driver',$config->mail_driver);
		Config::set('mail.host',$config->mail_host);
		Config::set('mail.port',$config->mail_port);
		Config::set('mail.encryption',$config->mail_encryption);
		Config::set('mail.username',$config->mail_username);
		Config::set('mail.password',$config->mail_password);
		$from = Config::get('mail.from');

		$stage_email = Stage::select("send_email_flag")->where("stage_id", $to_stage_id)->first();

		if($stage_email->send_email_flag==1) {
			try {
				$assessor = EmployeeSnapshot::find($assessor_id);
				$assessor_id = $this->get_emp_snapshot();
				$emp_snap = DB::select("
					SELECT qdh.data_header_id, 
					CONCAT(es.emp_first_name, ' ', es.emp_last_name) emp_name,
					es.email,
					ifnull(rsa.edit_flag, 0) edit_flag
					FROM questionaire_data_header qdh
					LEFT JOIN employee_snapshot es ON es.emp_snapshot_id = qdh.emp_snapshot_id
					LEFT JOIN level_stage_authorize rsa ON rsa.stage_id = qdh.data_stage_id 
					AND rsa.level_id = '{$assessor_id->level_id}'
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
				$to = ['thawatchai@goingjesse.com', 'chokanan@goingjesse.com'];

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

    function get_emp_snapshot() {
    	try {
    		$is_emp = EmployeeSnapshot::select("emp_snapshot_id","level_id","job_function_id")->where("emp_code", Auth::id())->orderBy('start_date', 'desc')->firstOrFail();
    	} catch (ModelNotFoundException $e) {
			exit(json_encode(['status' => 404, 'data' => Auth::id().' not found in EmployeeSnapshot.']));
		}

		return $is_emp;
    }

    function get_emp_snapshot_id_with_date($start, $end) {
    	$between_date = "
    		AND qdh.questionaire_date = (
				SELECT MAX(qdhh.questionaire_date)
				FROM employee_snapshot ess
				INNER JOIN questionaire_data_header qdhh ON qdhh.emp_snapshot_id = ess.emp_snapshot_id
				WHERE ess.emp_code = es.emp_code
    	";
    	if(empty($start) && empty($end)) {
            $between_date .= " )";
        } else if(empty($start)) {
            $between_date .= " AND qdhh.questionaire_date BETWEEN '' AND '{$end}' )";
        } else if(empty($end)) {
            $between_date .= " AND qdhh.questionaire_date >= '{$start}' )";
        } else {
            $between_date .= " AND qdhh.questionaire_date BETWEEN '{$start}' AND '{$end}' )";
        }

    	$is_emp = DB::select("
			SELECT es.emp_snapshot_id
			FROM employee_snapshot es
			INNER JOIN questionaire_data_header qdh ON qdh.emp_snapshot_id = es.emp_snapshot_id
			WHERE es.emp_code = '".Auth::id()."'
			".$between_date."
		");

		return $is_emp;
    }

    function all_emp() {
    	$all_emp = DB::select("
			SELECT sum(b.is_all_employee) count_no
			from employee a
			left outer join appraisal_level b
			on a.level_id = b.level_id
			where emp_code = ?
			", array(Auth::id()));

    	return $all_emp;
    }

    function workflow_stage($stage_id) {
        $item = DB::table("workflow_stage")->select("to_stage_id")->where("from_stage_id", $stage_id)->first();
        return $item;
    }

    public function role_authorize_add() {
        $data = [];
        $level = $this->get_emp_snapshot();

        $data = DB::table("level_stage_authorize")
        		->select("add_flag")
        		->where("level_id", $level->level_id)
        		->where("stage_id", 1)
        		->first();

    	if(empty($data)) {
    		return response()->json(['status' => 400, 'add_flag' => 0, 'errors' => 'Level not Assign to LevelStageAuthorize']);
    	} else {
    		return response()->json(['status' => 200, 'add_flag' => $data->add_flag, 'errors' => []]);
    	}
    }

    function check_action($current_stage_id, $level) {
        $check_button = DB::select("
            SELECT lsa.add_flag, 
            		lsa.edit_flag, 
            		lsa.view_flag, 
            		lsa.view_comment_flag
			FROM stage s
			INNER JOIN level_stage_authorize lsa ON lsa.stage_id = s.stage_id
            WHERE s.stage_id = '{$current_stage_id}'
            AND lsa.level_id = '{$level->level_id}'
        ");

        if(empty($check_button)) {
        	exit(json_encode(
        		[
        			'status' => 404, 
        			'data' => 'level_id '.$level->level_id.' and stage_id '.$current_stage_id.' not found in LevelStageAuthorize.'
        		]
        	));
        }

        if($check_button[0]->view_flag==0) {
        	
        	if($check_button[0]->add_flag==1
        		||$check_button[0]->edit_flag==1
        		||$check_button[0]->view_comment_flag==1) {

        		$workflow_stage = $this->workflow_stage($current_stage_id);
        		if(empty($workflow_stage)) {
        			$actions = [];
        		} else {
        			 $actions = DB::select("
			            SELECT s.stage_id,
			            		s.stage_name,
			            		s.is_require_answer,
			            		lsa.view_comment_flag
						FROM stage s
						INNER JOIN level_stage_authorize lsa ON lsa.stage_id = s.stage_id
			            WHERE s.stage_id IN ({$workflow_stage->to_stage_id})
			            AND lsa.level_id = '{$level->level_id}'
			        ");
        		}
        	} else {
        		$actions = [];
        	}

        } else {
        	$actions = [];
        }

        return $actions;
    }

    function get_tree_emp($emp_code) {
    	$re_emp = array();
    	$emp_list = array();

    	$emps = DB::select("
    		select distinct emp_code
    		from employee_snapshot
    		where chief_emp_code = ?
    		", array($emp_code));

    	foreach ($emps as $e) {
    		$emp_list[] = $e->emp_code;
    		$re_emp[] = $e->emp_code;
    	}

    	$emp_list = array_unique($emp_list);
		// Get array keys
    	$arrayKeys = array_keys($emp_list);
		// Fetch last array key
    	$lastArrayKey = array_pop($arrayKeys);
		//iterate array
    	$in_emp = '';
    	foreach($emp_list as $k => $v) {
    		if($k == $lastArrayKey) {
				//during array iteration this condition states the last element.
    			$in_emp .= "'" . $v . "'";
    		} else {
    			$in_emp .= "'" . $v . "'" . ',';
    		}
    	}

    	do {

    		if(empty($in_emp)) {
    			$in_emp = "null";
    			$re_emp[] = $emp_code;
    		}

    		$emp_list = array();
    		$emp_items = DB::select("
    			select distinct emp_code
    			from employee_snapshot
    			where chief_emp_code in ({$in_emp})
    			and chief_emp_code != emp_code
    			and is_active = 1
    			");

    		foreach ($emp_items as $e) {
    			$emp_list[] = $e->emp_code;
    			$re_emp[] = $e->emp_code;
    		}

    		$emp_list = array_unique($emp_list);
			// Get array keys
    		$arrayKeys = array_keys($emp_list);
			// Fetch last array key
    		$lastArrayKey = array_pop($arrayKeys);
			//iterate array
    		$in_emp = '';
    		foreach($emp_list as $k => $v) {
    			if($k == $lastArrayKey) {
						//during array iteration this condition states the last element.
    				$in_emp .= "'" . $v . "'";
    			} else {
    				$in_emp .= "'" . $v . "'" . ',';
    			}
    		}
    	} while (!empty($emp_list));

    	$re_emp = array_unique($re_emp);
		// Get array keys
    	$arrayKeys = array_keys($re_emp);
		// Fetch last array key
    	$lastArrayKey = array_pop($arrayKeys);
		//iterate array
    	$in_emp = '';
    	foreach($re_emp as $k => $v) {
    		if($k == $lastArrayKey) {
				//during array iteration this condition states the last element.
    			$in_emp .= "'" . $v . "'";
    		} else {
    			$in_emp .= "'" . $v . "'" . ',';
    		}
    	}
    	return $in_emp;
    }

    function calculate_score($data_header_id, $in_section) {
    	$cal_score = DB::select("
    		SELECT COUNT(DISTINCT customer_id) count_customer, 
		    		SUM(qdd.score) score, 
		    		SUM(qdd.full_score) full_score, 
		    		COUNT(DISTINCT qdd.question_id) count_question,
		    		(
		    			SELECT SUM(qdd.full_score)
			    		FROM questionaire_data_detail qdd
			    		INNER JOIN questionaire_section qs ON qs.section_id = qdd.section_id
			    		WHERE qs.is_cust_search = 1
			    		AND qdd.is_not_applicable = 1
			    		AND qdd.data_header_id = '{$data_header_id}'
			    		AND qdd.section_id IN ({$in_section})
		    		) sum_applicable
    		FROM questionaire_data_detail qdd
    		INNER JOIN questionaire_section qs ON qs.section_id = qdd.section_id
    		WHERE qs.is_cust_search = 1
    		AND qdd.data_header_id = '{$data_header_id}'
    		AND qdd.section_id IN ({$in_section})
    	");

    	if(!empty($cal_score[0]->full_score) && !empty($cal_score[0]->full_score) 
    		&& !empty($cal_score[0]->full_score) && !empty($cal_score[0]->full_score)) {

    		$total = $cal_score[0]->full_score - $cal_score[0]->sum_applicable;
    		$total_score = $total / $cal_score[0]->count_customer;
    		$full_score = $cal_score[0]->score / $cal_score[0]->count_customer;

    		$items = QuestionaireDataHeader::select("full_score", "total_score")
    				->where("data_header_id", $data_header_id)->first();
    		$full_score = $full_score + $items->full_score;
    		$total_score = $total_score + $items->total_score;

    	} else {
    		$full_score = null;
    		$total_score = null;
    	}

    	return ['full_score' => $full_score, 'total_score' => $total_score];
    }

    function query_answer($answer_type_id, $question_id, $data_header_id, $customer_id) {

    	if($answer_type_id==3 || $answer_type_id==4) {
    		$select_score = "SUM(score)";
    	} else {
    		$select_score = "MAX(score)";
    	}

    	if(!empty($customer_id) && !empty($data_header_id) && !empty($question_id)) { //edit store
    		$item = DB::select("
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
						(
							SELECT {$select_score}
							FROM answer 
							WHERE question_id = '{$question_id}'
							AND is_not_applicable = 0
						) full_score,
						IF ( an.answer_id = qdd.answer_id, 1, 0) is_check
					FROM questionaire_data_detail qdd
					INNER JOIN answer an ON an.question_id = qdd.question_id
					AND qdd.question_id = '{$question_id}'
					AND qdd.data_header_id = '{$data_header_id}'
					AND qdd.customer_id = '{$customer_id}'
					GROUP BY is_check, an.answer_id
					ORDER BY is_check DESC, an.answer_id
				)d1
				GROUP BY answer_id
			");
		} else if(!empty($data_header_id) && !empty($question_id)) { // assign template
			$item = DB::select("
				SELECT *
				FROM (
					SELECT qdd.data_detail_id,
						an.answer_id,
						an.row_name,
						an.answer_name,
						an.score,
						an.is_not_applicable,
						qdd.desc_answer,
						(
							SELECT {$select_score}
							FROM answer 
							WHERE question_id = '{$question_id}'
							AND is_not_applicable = 0
						) full_score,
						IF (an.answer_id = qdd.answer_id, 1, 0) is_check
					FROM questionaire_data_detail qdd
					INNER JOIN answer an ON an.question_id = qdd.question_id
					AND qdd.question_id = '{$question_id}'
					AND qdd.data_header_id = '{$data_header_id}'
					GROUP BY is_check, an.answer_id
					ORDER BY is_check DESC, an.answer_id
				)d1
				GROUP BY answer_id
			");
		} else { // generate template
			$item = DB::select("
				SELECT ans.question_id,
						ans.answer_id, 
						ans.row_name, 
						ans.answer_name, 
						ans.score, 
						ans.is_not_applicable, 
						'' desc_answer,
						(
							SELECT {$select_score}
							FROM answer 
							WHERE question_id = '{$question_id}'
							AND is_not_applicable = 0
						) full_score,
						IF(is_applicable > 0 AND ans.is_not_applicable = 1, 1,
							IF(is_applicable > 0, 0, 
								IF(ans.score = 0, 1, 0)
							)
						) is_check
				FROM answer ans
				LEFT OUTER JOIN (
					SELECT question_id, SUM(is_not_applicable) is_applicable
					FROM answer
					GROUP BY question_id
				)cab ON cab.question_id = ans.question_id
				WHERE ans.question_id = '{$question_id}'
				ORDER BY ans.seq_no ASC
			");
		}

		return $item;
    }

    function filter_checkbox($data_checkbox, $in_question) {
    	$checkbox_data = array_filter($data_checkbox, function($data) use ($in_question) {
    		foreach($in_question as $v) {
    			if ($data['question_id'] == $v) {
    				return true;
    			}
    		}
    	});
    	return $checkbox_data;
    }

	public function auto_emp(Request $request) {
		$emp_name = $this->concat_emp_first_last_code($request->emp_name);
		$request->start_date = $this->format_date($request->start_date);
		$request->end_date = $this->format_date($request->end_date);

		$all_emp = $this->all_emp();

		if ($all_emp[0]->count_no > 0) {
			$assessor = "";
		} else {
			$assessor_id = $this->get_emp_snapshot();

			$emp_snapshot_id_with_date = $this->get_emp_snapshot_id_with_date($request->start_date, $request->end_date);
			$emp_snapshot_id_with_datee = empty($emp_snapshot_id_with_date[0]->emp_snapshot_id) ? "" : $emp_snapshot_id_with_date[0]->emp_snapshot_id;

			$assessor = "AND (qdh.emp_snapshot_id = '{$emp_snapshot_id_with_datee}' OR qdh.assessor_id = '{$assessor_id->emp_snapshot_id}')";
		}

		$items = DB::select("
			SELECT es.emp_snapshot_id, 
					CONCAT(es.emp_first_name, ' ', es.emp_last_name) emp_name,
					p.position_code,
					es.emp_code
			FROM employee_snapshot es
			LEFT JOIN position p ON p.position_id = es.position_id
			INNER JOIN questionaire_data_header qdh ON qdh.emp_snapshot_id = es.emp_snapshot_id
			INNER JOIN questionaire qn ON qn.questionaire_id = qdh.questionaire_id
			WHERE (
				es.emp_first_name LIKE '%{$emp_name}%'
				OR es.emp_last_name LIKE '%{$emp_name}%'
				OR p.position_code LIKE '%{$emp_name}%'
			)
			AND qdh.questionaire_date BETWEEN '{$request->start_date}' AND '{$request->end_date}'
			AND qn.questionaire_type_id = '{$request->questionaire_type_id}'
			".$assessor."
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
					#CONCAT(chief.emp_first_name, ' ', chief.emp_last_name) chief_emp_name,
					(
					 SELECT CONCAT(am.emp_first_name, ' ', am.emp_last_name)
					 FROM employee_snapshot am
					 WHERE am.emp_snapshot_id = '{$this->get_emp_snapshot()->emp_snapshot_id}'
					) chief_emp_name,
					p.position_code,
					es.emp_code
			FROM employee_snapshot es
			INNER JOIN job_function jf ON jf.job_function_id = es.job_function_id
			INNER JOIN position p ON p.position_id = es.position_id
			#LEFT JOIN employee_snapshot chief ON chief.emp_code = es.chief_emp_code
			WHERE (
				es.emp_first_name LIKE '%{$emp_name}%'
				OR es.emp_last_name LIKE '%{$emp_name}%'
				OR p.position_code LIKE '%{$emp_name}%'
			) AND es.emp_snapshot_id NOT IN (
				SELECT qdh.emp_snapshot_id
				FROM questionaire_data_header qdh
				INNER JOIN questionaire qn ON qn.questionaire_id = qdh.questionaire_id
				WHERE qdh.questionaire_date = '{$request->date}'
				AND qn.questionaire_type_id = '{$request->questionaire_type_id}'
			) AND jf.is_evaluated = 1
			LIMIT 10
		");
		return response()->json($items);
	}

	public function auto_store(Request $request) {
		$position_code = empty($request->position_code) ? "" : "AND cp.position_code = '{$request->position_code}'";

		$items = DB::select("
			SELECT c.customer_id, c.customer_code, c.customer_name, c.customer_type
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

	public function list_questionaire_type(Request $request) {
		$items = DB::select("
			SELECT questionaire_type_id, questionaire_type
			FROM questionaire_type
			ORDER BY questionaire_type_id
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
			ORDER BY c.customer_name
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
				AND at.is_active = 1
			");

			foreach ($sub_items[$key]->sub_section as $key2 => $anv) {
				$sub_items[$key]->sub_section[$key2]->answer = $this->query_answer($anv->answer_type_id, $anv->question_id, $request->data_header_id, $request->customer_id);
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
					AND at.is_active = 1
					");

				foreach ($sub_items[$key]->sub_section[$key2]->question as $key3 => $ssq) {
					$sub_items[$key]->sub_section[$key2]->question[$key3]->answer = $this->query_answer($ssq->answer_type_id, $ssq->question_id, $request->data_header_id, $request->customer_id);
				}
			}
		}

		return response()->json(['customer'=> $customer, 'data' => $sub_items[0]]);
	}

	public function index(Request $request) {
		$request->start_date = $this->format_date($request->start_date);
		$request->end_date = $this->format_date($request->end_date);

		$emp_snapshot_id = empty($request->emp_snapshot_id) ? "" : "AND qdh.emp_snapshot_id = '{$request->emp_snapshot_id}'";

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

		$all_emp = $this->all_emp();

		if ($all_emp[0]->count_no > 0) {
			$level = "";
			$assessor = "";
		} else {
			$assessor_id = $this->get_emp_snapshot();

			$emp_snapshot_id_with_date = $this->get_emp_snapshot_id_with_date($request->start_date, $request->end_date);
			$emp_snapshot_id_with_datee = empty($emp_snapshot_id_with_date[0]->emp_snapshot_id) ? "" : $emp_snapshot_id_with_date[0]->emp_snapshot_id;

			$assessor = "AND (qdh.emp_snapshot_id = '{$emp_snapshot_id_with_datee}' OR qdh.assessor_id = '{$assessor_id->emp_snapshot_id}')";
			$level = "AND rsa.level_id  = '{$assessor_id->level_id}'";
		}

		$items = DB::select("
			SELECT qdh.data_stage_id, s.stage_name
			FROM questionaire_data_header qdh
			INNER JOIN stage s ON s.stage_id = qdh.data_stage_id
			INNER JOIN questionaire qn ON qn.questionaire_id = qdh.questionaire_id
			INNER JOIN questionaire_type qt ON qt.questionaire_type_id = qn.questionaire_type_id
			WHERE qn.questionaire_type_id = '{$request->questionaire_type_id}'
			".$between_date."
			".$assessor."
			".$emp_snapshot_id."
			GROUP BY qdh.data_stage_id
			ORDER BY qdh.data_stage_id
		");

		$header_query = DB::select("
			SELECT qdh.data_header_id
			FROM questionaire_data_header qdh
			INNER JOIN stage s ON s.stage_id = qdh.data_stage_id
			INNER JOIN questionaire qn ON qn.questionaire_id = qdh.questionaire_id
			INNER JOIN questionaire_type qt ON qt.questionaire_type_id = qn.questionaire_type_id
			WHERE qn.questionaire_type_id = '{$request->questionaire_type_id}'
			".$between_date."
			".$assessor."
			".$emp_snapshot_id."
			ORDER BY qdh.data_stage_id
		");

		$header_array_id = [];
		foreach ($header_query as $key => $value) {
            array_push($header_array_id, $value->data_header_id);
        }

        $header_id = empty(implode(',', $header_array_id)) ? "''" : implode(',', $header_array_id);

		foreach ($items as $key => $value) {

			$items[$key]->data = DB::select("
				SELECT qdh.data_header_id,
						qdh.emp_snapshot_id,
						qdh.questionaire_id,
						qn.questionaire_type_id,
						DATE_FORMAT(qdh.questionaire_date, '%d/%m/%Y') questionaire_date,
						p.position_code,
						qdh.assessor_id,
						CONCAT(es.emp_first_name, ' ', es.emp_last_name) emp_name,
						ifnull(rsa.edit_flag, 0) edit_flag, 
						ifnull(rsa.delete_flag, 0) delete_flag, 
						if(rsa.view_flag = 1 OR rsa.view_comment_flag = 1, 1, 0) view_flag
				FROM questionaire_data_header qdh
				INNER JOIN employee_snapshot es ON es.emp_snapshot_id = qdh.emp_snapshot_id
				INNER JOIN position p ON p.position_id = es.position_id
				INNER JOIN questionaire qn ON qn.questionaire_id = qdh.questionaire_id
				LEFT JOIN level_stage_authorize rsa ON rsa.stage_id = qdh.data_stage_id 
				".$level."
				WHERE qdh.data_stage_id = '{$value->data_stage_id}'
				AND qdh.data_header_id IN ({$header_id})
				GROUP BY qdh.data_header_id
				ORDER BY qdh.questionaire_date, qdh.data_header_id
				");
		}

		return response()->json($items);
	}

	public function generate_template(Request $request) {
		try {
			QuestionaireType::findOrFail($request->questionaire_type_id);
		} catch (ModelNotFoundException $e) {
			return response()->json(['status' => 404, 'data' => 'QuestionaireType not found.']);
		}

		$head = DB::select("
			SELECT qn.questionaire_id, qn.questionaire_name
			FROM questionaire_authorize qa
			INNER JOIN questionaire qn ON qn.questionaire_id = qa.questionaire_id
			INNER JOIN employee_snapshot es ON es.job_function_id = qa.job_function_id
			WHERE es.emp_snapshot_id = '{$request->emp_snapshot_id}'
			AND qa.questionaire_type_id = '{$request->questionaire_type_id}'
			");

		$head = $head[0];

		$sub_items = DB::select("
			SELECT section_id, section_name, is_cust_search, is_show_report, report_url
			FROM questionaire_section
			WHERE questionaire_id = '{$head->questionaire_id}'
			");

		foreach ($sub_items as $key => $qsv) {
			$sub_items[$key]->sub_section =  DB::select("
				SELECT q.question_id, q.answer_type_id, q.parent_question_id, q.question_name, q.pass_score
				FROM question q
				INNER JOIN answer_type at ON at.answer_type_id = q.answer_type_id
				WHERE q.section_id = {$qsv->section_id}
				AND q.parent_question_id IS NULL
				AND at.is_active = 1
				ORDER BY q.seq_no ASC
				");

			foreach ($sub_items[$key]->sub_section as $key2 => $anv) {
				$sub_items[$key]->sub_section[$key2]->answer = $this->query_answer($anv->answer_type_id, $anv->question_id, null, null);
			}

			foreach ($sub_items[$key]->sub_section as $key2 => $anv) {
				$sub_items[$key]->sub_section[$key2]->question =  DB::select("
					SELECT q.question_id, q.answer_type_id, at.is_show_comment, q.parent_question_id, q.question_name
					FROM question q
					INNER JOIN answer_type at ON at.answer_type_id = q.answer_type_id
					WHERE q.parent_question_id = {$anv->question_id}
					AND at.is_active = 1
					ORDER BY q.seq_no ASC
					");

				foreach ($sub_items[$key]->sub_section[$key2]->question as $key3 => $ssq) {
					$sub_items[$key]->sub_section[$key2]->question[$key3]->answer = $this->query_answer($ssq->answer_type_id, $ssq->question_id, null, null);
				}
			}
		}

		try {
			$level = $this->get_emp_snapshot();
			$default_stage_id = AppraisalLevel::find($level->level_id)->default_stage_id;
			$current_stage = Stage::findOrFail($default_stage_id);
		} catch (ModelNotFoundException $e) {
			return response()->json(['status' => 404, 'data' => 'Stage not found.']);
		}

		$stage = [];

        $actions = $this->check_action($current_stage->stage_id, $level);

		return response()->json([
			'status' => 200,
			'head' => $head, 
			'data' => $sub_items, 
			'stage' => $stage, 
			'current_stage' => $current_stage, 
			'to_stage' => $actions
		]);
	}

	public function assign_template(Request $request) {
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
			#CONCAT(chief.emp_first_name, ' ', chief.emp_last_name) chief_emp_name,
			qdh.assessor_id,
			CONCAT(ases.emp_first_name, ' ', ases.emp_last_name) chief_emp_name,
			p.position_code,
			q.questionaire_type_id,
			q.questionaire_id,
			q.questionaire_name
			FROM questionaire_data_header qdh
			LEFT JOIN employee_snapshot es ON es.emp_snapshot_id = qdh.emp_snapshot_id
			#LEFT JOIN employee_snapshot chief ON chief.emp_code = es.chief_emp_code
			LEFT JOIN employee_snapshot ases ON ases.emp_snapshot_id = qdh.assessor_id
			LEFT JOIN position p ON p.position_id = es.position_id
			INNER JOIN questionaire q ON q.questionaire_id = qdh.questionaire_id
			WHERE qdh.data_header_id = {$request->data_header_id}
			");

		$head = $head[0];

		$sub_items = DB::select("
			SELECT section_id, section_name, is_cust_search, is_show_report, report_url
			FROM questionaire_section
			WHERE questionaire_id = '{$head->questionaire_id}'
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
				AND at.is_active = 1
				");

			if($v_qdd->is_cust_search==0) {
				foreach ($sub_items[$key]->sub_section as $key2 => $anv) {
					$sub_items[$key]->sub_section[$key2]->answer = $this->query_answer($anv->answer_type_id, $anv->question_id, $request->data_header_id, null);
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
						AND at.is_active = 1
						");

					foreach ($sub_items[$key]->sub_section[$key2]->question as $key3 => $ssq) {
						$sub_items[$key]->sub_section[$key2]->question[$key3]->answer = $this->query_answer($ssq->answer_type_id, $ssq->question_id, $request->data_header_id, null);
					}
				}
			} else {
				foreach ($sub_items[$key]->sub_section as $key2 => $anv) {
					$sub_items[$key]->sub_section[$key2]->answer = $this->query_answer($anv->answer_type_id, $anv->question_id, null, null);
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
						AND at.is_active = 1
						");

					foreach ($sub_items[$key]->sub_section[$key2]->question as $key3 => $ssq) {
						$sub_items[$key]->sub_section[$key2]->question[$key3]->answer = $this->query_answer($ssq->answer_type_id, $ssq->question_id, null, null);
					}
				}
			}
		}

		$stage = DB::select("
			SELECT CONCAT(es.emp_first_name, ' ',es.emp_last_name) emp_name, CONCAT(chief.emp_first_name, ' ',chief.emp_last_name) chief_emp_name, qds.remark, DATE_FORMAT(qds.created_dttm, '%d/%m/%Y %H:%i:%s') created_dttm, qds.created_by, s.stage_name from_action, st.stage_name to_action
			FROM questionaire_data_stage qds
			INNER JOIN employee_snapshot es ON es.emp_snapshot_id = qds.to_emp_snapshot_id
			LEFT JOIN employee_snapshot chief ON chief.emp_snapshot_id = qds.from_emp_snapshot_id
			LEFT JOIN stage s ON s.stage_id = qds.from_stage_id
			LEFT JOIN stage st ON st.stage_id = qds.to_stage_id
			WHERE data_header_id = '{$request->data_header_id}'
			");

		$level = $this->get_emp_snapshot();
		$current_stage = DB::table("stage")->select("stage_id")->where("stage_id", $data_header->data_stage_id)->first();

		$this->check_permission($data_header->data_stage_id, $data_header->data_header_id);
        
        $actions = $this->check_action($current_stage->stage_id, $level);

		return response()->json([
			'status' => 200,
			'head' => $head, 
			'data' => $sub_items, 
			'stage' => $stage, 
			'current_stage' => $current_stage,
			'to_stage' => $actions
		]);
	}

	public function store(Request $request) {
		try {
			$config = SystemConfiguration::firstOrFail();
		} catch (ModelNotFoundException $e) {
			return response()->json(['status' => 404, 'data' => 'System Configuration not found in DB.']);
		}

		$assessor = $this->get_emp_snapshot();
		
		DB::beginTransaction();
		$errors = [];
		$errors_validator = [];
		$section_unique = [];
		$validator = Validator::make([
			'questionaire_id' => $request->questionaire_id,
			'questionaire_date' => $request->questionaire_date,
			'emp_snapshot_id' => $request->emp_snapshot_id,
			'total_score' => $request->total_score,
			'full_score' => $request->full_score
		], [
			'questionaire_id' => 'required|integer',
			'questionaire_date' => 'required|date_format:d/m/Y',
			'emp_snapshot_id' => 'required|integer',
			'total_score' => 'required|between:0,99.99',
			'full_score' => 'required|between:0,99.99'
		]);

		if($validator->fails()) {
			$errors_validator[] = $validator->errors();
		}

		if(!empty($request['detail'])) {
			foreach ($request['detail'] as $d) {
				$validator_detail = Validator::make([
					'section_id' => $d['section_id'],
					'question_id' => $d['question_id'],
					'answer_id' => $d['answer_id'],
					'score' => $d['score'],
					'full_score' => $d['full_score'],
					'is_not_applicable' => $d['is_not_applicable']
				], [
					'section_id' => 'required|integer',
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
		$h->assessor_id = $assessor->emp_snapshot_id;
		$h->pass_score = Questionaire::find($request->questionaire_id)->pass_score;
		$h->total_score = $request->total_score;
		$h->full_score = $request->full_score;
		$h->created_by = Auth::id();
		$h->updated_by = Auth::id();
		try {
			$h->save();
			$s = new QuestionaireDataStage;
			$s->data_header_id = $h->data_header_id;
			$s->from_emp_snapshot_id = $assessor->emp_snapshot_id;
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
					$dt->customer_id = empty($d['customer_id']) ? null : $d['customer_id'];
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
						$section_unique[] = $d['section_id'];
					} catch (Exception $e) {
						$errors[] = ['QuestionaireDataDetail' => substr($e, 0, 255)];
					}
				}

				$in_section = empty(implode(',', $section_unique)) ? "''" : implode(',', array_unique($section_unique));
				$cal_score = $this->calculate_score($h->data_header_id, $in_section);
				if(!empty($cal_score['full_score']) && !empty($cal_score['total_score'])) {
					QuestionaireDataHeader::where('data_header_id', $h->data_header_id)->update([
						'full_score' => $cal_score['full_score'],
						'total_score' => $cal_score['total_score'], 
						'updated_by' => Auth::id()
					]);
				}
			}
		} catch (Exception $e) {
			$errors[] = ['QuestionaireDataHeader' => substr($e, 0, 255)];
		}

		empty($errors) ? DB::commit() : DB::rollback();
		empty($errors) ? $status = 200 : $status = 400;

		if($status==200) {
			$errors[] = $this->send_email($config, $request->stage['to_stage_id'], $h->data_header_id, $h->assessor_id, $s->status);
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

		$assessor = $this->get_emp_snapshot();
		
		DB::beginTransaction();
		$errors = [];
		$errors_validator = [];
		$section_unique = [];
		$question_unique = [];
		$validator = Validator::make([
			'emp_snapshot_id' => $request->emp_snapshot_id,
			'total_score' => $request->total_score,
			'full_score' => $request->full_score
		], [
			'emp_snapshot_id' => 'required|integer',
			'total_score' => 'required|between:0,99.99',
			'full_score' => 'required|between:0,99.99'
		]);

		if($validator->fails()) {
			$errors_validator[] = $validator->errors();
		}

		if(!empty($request['detail'])) {
			foreach ($request['detail'] as $d) {
				$validator_detail = Validator::make([
					'section_id' => $d['section_id'],
					'question_id' => $d['question_id'],
					'answer_id' => $d['answer_id'],
					'score' => $d['score'],
					'full_score' => $d['full_score'],
					'is_not_applicable' => $d['is_not_applicable']
				], [
					'section_id' => 'required|integer',
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

		$h->emp_snapshot_id = $request->emp_snapshot_id;
		$h->total_score = $request->total_score;
		$h->full_score = $request->full_score;
		$h->updated_by = Auth::id();
		try {
			$h->save();
			$s = new QuestionaireDataStage;
			$s->data_header_id = $h->data_header_id;
			$s->from_emp_snapshot_id = $assessor->emp_snapshot_id;
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
				$errors[] = ['QuestionaireDataStage or QuestionaireDataHeader' => substr($e, 0, 400)];
			}

			if(!empty($request['detail'])) {
				foreach ($request['detail'] as $keyD => $d) {
					$ans_type = Question::select("answer_type_id")->where("question_id", $d['question_id'])->first();
					if($ans_type->answer_type_id==3 || $ans_type->answer_type_id==4) {
						$question_unique[] = $d['question_id'];
					} else {
						if(empty($d['data_detail_id'])) {
							$dt = new QuestionaireDataDetail;
							$dt->data_header_id = $h->data_header_id;
							$dt->section_id = $d['section_id'];
							$dt->customer_id = empty($d['customer_id']) ? null : $d['customer_id'];
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
							$dt->customer_id = empty($d['customer_id']) ? null : $d['customer_id'];
							$dt->question_id = $d['question_id'];
							$dt->answer_id = $d['answer_id'];
							$dt->score = $d['score'];
							$dt->full_score = $d['full_score'];
							$dt->is_not_applicable = $d['is_not_applicable'];
							$dt->desc_answer = $d['desc_answer'];
							$dt->updated_by = Auth::id();
						}
					}
					try {
						$dt->save();
						$section_unique[] = $d['section_id'];
					} catch (Exception $e) {
						$errors[] = ['QuestionaireDataDetail' => substr($e, 0, 400)];
					}
				}

				//destroy checkbox and insert new
				$in_question = array_unique($question_unique);
				foreach ($in_question as $key) {
					QuestionaireDataDetail::where('question_id', $key)->delete();
				}
				$checkbox_data = $this->filter_checkbox($request['detail'], $in_question);

				foreach ($checkbox_data as $keyD => $d) {
					$dc = new QuestionaireDataDetail;
					$dc->data_header_id = $h->data_header_id;
					$dc->section_id = $d['section_id'];
					$dc->customer_id = empty($d['customer_id']) ? null : $d['customer_id'];
					$dc->question_id = $d['question_id'];
					$dc->answer_id = $d['answer_id'];
					$dc->score = $d['score'];
					$dc->full_score = $d['full_score'];
					$dc->is_not_applicable = $d['is_not_applicable'];
					$dc->desc_answer = $d['desc_answer'];
					$dc->created_by = Auth::id();
					$dc->updated_by = Auth::id();
					try {
						$dc->save();
					} catch (Exception $e) {
						$errors[] = ['QuestionaireDataDetail(checkbox)' => substr($e, 0, 400)];
					}
				}

				//calculate total_score and full_score
				$in_section = empty(implode(',', $section_unique)) ? "''" : implode(',', array_unique($section_unique));
				$cal_score = $this->calculate_score($h->data_header_id, $in_section);
				if(!empty($cal_score['full_score']) && !empty($cal_score['total_score'])) {
					QuestionaireDataHeader::where('data_header_id', $h->data_header_id)->update([
						'full_score' => $cal_score['full_score'],
						'total_score' => $cal_score['total_score'], 
						'updated_by' => Auth::id()
					]);
				}
			}
		} catch (Exception $e) {
			$errors[] = ['QuestionaireDataHeader' => substr($e, 0, 400)];
		}

		empty($errors) ? DB::commit() : DB::rollback();
		empty($errors) ? $status = 200 : $status = 400;

		if($status==200) {
			$errors[] = $this->send_email($config, $request->stage['to_stage_id'], $h->data_header_id, $h->assessor_id, $s->status);
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
			QuestionaireSection::findOrFail($request->section_id);
		} catch (ModelNotFoundException $e) {
			return response()->json(['status' => 404, 'data' => 'QuestionaireSection not found.']);
		}

		try {
			DB::table('questionaire_data_detail')->where('data_header_id', '=', $request->data_header_id)->where('customer_id', '=', $request->customer_id)->where('section_id', '=', $request->section_id)->delete();
		} catch (Exception $e) {
			if ($e->errorInfo[1] == 1451) {
				return response()->json(['status' => 400, 'data' => 'Cannot delete because this QuestionaireDataDetail is in use.']);
			} else {
				return response()->json($e->errorInfo);
			}
		}

		return response()->json(['status' => 200]);
	}

	// Report
	public function auto_emp_report(Request $request) {
		$emp_name = $this->concat_emp_first_last_code($request->emp_name);
		$all_emp = $this->all_emp();
		$in_emp = $this->get_tree_emp(Auth::id());

		if ($all_emp[0]->count_no > 0) {
			$items = DB::select("
				SELECT es.emp_snapshot_id, CONCAT(es.emp_first_name, ' ', es.emp_last_name, ' (',p.position_code,')') emp_name
				FROM employee_snapshot es
				INNER JOIN position p ON p.position_id = es.position_id
				INNER JOIN job_function jf ON jf.job_function_id = es.job_function_id
				WHERE es.start_date IN (
					SELECT MAX(start_date) start_date
					FROM employee_snapshot
					WHERE is_active = 1
					AND es.emp_snapshot_id = emp_snapshot_id
				) AND (
					es.emp_first_name LIKE '%{$emp_name}%'
					OR es.emp_last_name LIKE '%{$emp_name}%'
					OR p.position_code LIKE '%{$emp_name}%'
				) AND jf.is_evaluated = 1
				ORDER BY es.emp_first_name, es.emp_last_name
				LIMIT 15
			");
		} else {
			$items = DB::select("
				SELECT es.emp_snapshot_id, CONCAT(es.emp_first_name, ' ', es.emp_last_name, ' (',p.position_code,')') emp_name
				FROM employee_snapshot es
				INNER JOIN position p ON p.position_id = es.position_id
				INNER JOIN job_function jf ON jf.job_function_id = es.job_function_id
				WHERE es.start_date IN (
					SELECT MAX(start_date) start_date
					FROM employee_snapshot
					WHERE is_active = 1
					AND es.emp_snapshot_id = emp_snapshot_id
				) AND (
					es.emp_first_name LIKE '%{$emp_name}%'
					OR es.emp_last_name LIKE '%{$emp_name}%'
					OR p.position_code LIKE '%{$emp_name}%'
				)
				AND es.emp_code IN ({$in_emp})
				AND jf.is_evaluated = 1
				ORDER BY es.emp_first_name, es.emp_last_name
				LIMIT 15
			");
			// $emp_code = Auth::id();
			// $emp_snap = $this->get_emp_snapshot();
			// $level = AppraisalLevel::getLevelParentDown($emp_snap->level_id);
			// $level_array = [];
			// foreach ($level as $key => $value) {
			// 	array_push($level_array, $value['level_id']);
			// }
			// $level_array= implode(",",$level_array);

			// $job_function = DB::table("job_function")->select("is_evaluated")->where("job_function_id", $emp_snap->job_function_id)->first();

			// $query = "
			// 	SELECT es.emp_snapshot_id, CONCAT(es.emp_first_name, ' ', es.emp_last_name, ' (',p.position_code,')') emp_name
			// 	FROM employee_snapshot es
			// 	INNER JOIN position p ON p.position_id = es.position_id
			// 	INNER JOIN job_function jf ON jf.job_function_id = es.job_function_id
			// 	WHERE es.start_date IN (
			// 		SELECT MAX(start_date) start_date
			// 		FROM employee_snapshot
			// 		WHERE is_active = 1
			// 		AND es.emp_snapshot_id = emp_snapshot_id
			// 	) AND (
			// 		es.emp_first_name LIKE '%{$emp_name}%'
			// 		OR es.emp_last_name LIKE '%{$emp_name}%'
			// 		OR p.position_code LIKE '%{$emp_name}%'
			// 	) AND jf.is_evaluated = 1 
			// ";

			// if($job_function->is_evaluated==1) {
			// 	$query .= "AND es.level_id IN ({$level_array}) AND es.emp_code = '{$emp_code}' ";
			// } else {
			// 	$query .= "AND es.level_id IN ({$level_array}) ";
			// }

			// $query .= "ORDER BY es.emp_first_name, es.emp_last_name LIMIT 15";

			// $items = DB::select($query);
		}

		return response()->json($items);
	}

	public function auto_assessor_report(Request $request) {
		$emp_name = $this->concat_emp_first_last_code($request->emp_name);
		$items = DB::select("
			SELECT es.emp_snapshot_id, CONCAT(es.emp_first_name, ' ', es.emp_last_name, ' (',p.position_code,')') emp_name
			FROM employee_snapshot es
			INNER JOIN position p ON p.position_id = es.position_id
			INNER JOIN appraisal_level al ON al.level_id = es.level_id
			WHERE es.start_date IN (
				SELECT MAX(start_date) start_date
				FROM employee_snapshot
				WHERE is_active = 1
				AND es.emp_snapshot_id = emp_snapshot_id
			) AND (
				es.emp_first_name LIKE '%{$emp_name}%'
				OR es.emp_last_name LIKE '%{$emp_name}%'
				OR p.position_code LIKE '%{$emp_name}%'
			) AND al.appraisal_level_name = 'ASM'
			ORDER BY es.emp_first_name, es.emp_last_name
			LIMIT 15
		");
		return response()->json($items);
	}

	public function export_transaction(Request $request) {
		set_time_limit(1000);
		ini_set('memory_limit', '5012M');
		$fileName = "WWWR Transaction Report ".date('Ymd His');
		
		$request->start_date = $this->format_date($request->start_date);
		$request->end_date = $this->format_date($request->end_date);

		try {
			QuestionaireType::findOrFail($request->questionaire_type_id);
		} catch (Exception $e) {
			return response()->json(['status' => 404, 'data' => 'QuestionaireType not found.']);
		}

		$assessor_id = empty($request->assessor_id) ? "" : "AND qdh.assessor_id = '{$request->assessor_id}' ";
		$items = DB::select("
			SELECT qnt.questionaire_type,
					qn.questionaire_name,
					qdh.questionaire_date,
					asess.emp_code assessor_code,
					CONCAT(asess.emp_first_name, ' ', asess.emp_last_name) assessor_name,
					asm.emp_code asm_code,
					CONCAT(asm.emp_first_name, ' ', asm.emp_last_name) asm_name,
					es.emp_code tse_code,
					CONCAT(es.emp_first_name, ' ', es.emp_last_name) tse_name,
					jf.job_function_name,
					es.distributor_code,
					es.distributor_name,
					es.region,
					qds.status,
					qs.section_name,
					q.question_name,
					(SELECT MAX(score) FROM answer WHERE question_id = q.question_id) full_score,
					ans.answer_name,
					qdd.score,
					qdd.desc_answer,
					c.customer_code, 
					c.customer_name, 
					c.customer_type, 
					c.industry_class
				FROM questionaire_data_header qdh
				INNER JOIN questionaire_data_stage qds ON qds.data_header_id = qdh.data_header_id
				LEFT JOIN stage st ON st.stage_id = qdh.data_stage_id AND st.stage_id = qds.data_stage_id
				INNER JOIN questionaire qn ON qn.questionaire_id = qdh.questionaire_id
				INNER JOIN questionaire_type qnt ON qnt.questionaire_type_id = qn.questionaire_type_id
				INNER JOIN questionaire_data_detail qdd ON qdd.data_header_id = qdh.data_header_id
				LEFT JOIN customer c ON c.customer_id = qdd.customer_id
				INNER JOIN questionaire_section qs ON qs.section_id = qdd.section_id
				INNER JOIN question q ON q.question_id = qdd.question_id
				INNER JOIN answer ans ON ans.answer_id = qdd.answer_id
				INNER JOIN employee_snapshot es ON es.emp_snapshot_id = qdh.emp_snapshot_id
				INNER JOIN job_function jf ON jf.job_function_id = es.job_function_id
				LEFT JOIN employee_snapshot asess ON asess.emp_snapshot_id = qdh.assessor_id
				LEFT JOIN employee_snapshot asm ON asm.emp_code = es.chief_emp_code
				WHERE q.parent_question_id IS NOT NULL
				AND qdh.questionaire_date BETWEEN '{$request->start_date}' AND '{$request->end_date}'
				AND qdh.emp_snapshot_id = '{$request->emp_snapshot_id}'
				".$assessor_id."
				GROUP BY qdd.answer_id
		");

	    $resultArray = json_decode(json_encode($items), true);
	    Excel::create($fileName, function($excel) use ($resultArray) {
	    	$excel->sheet('WWWR Transaction Report', function($sheet) use ($resultArray) {
	    		$sheet->fromArray($resultArray);
	    	});
	    })->download('xlsx');
	}
}
