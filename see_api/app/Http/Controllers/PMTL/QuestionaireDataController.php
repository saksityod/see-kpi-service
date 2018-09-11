<?php

namespace App\Http\Controllers\PMTL;

use App\QuestionaireSection;
use App\QuestionaireDataHeader;
use App\QuestionaireDataDetail;
use App\QuestionaireDataStage;
use App\EmployeeSnapshot;

use Auth;
use DB;
use File;
use Validator;
use Excel;
use Exception;
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

	public function auto_emp(Request $request) {
		$items = DB::select("
			SELECT es.emp_snapshot_id, 
					CONCAT(es.emp_first_name, ' ', es.emp_last_name) emp_name, 
					es.distributor_name,
					CONCAT(chief.emp_first_name, ' ', chief.emp_last_name) chief_emp_name,
					p.position_code
			FROM employee_snapshot es
			LEFT JOIN position p ON p.position_id = es.position_id
			LEFT JOIN employee_snapshot chief ON chief.emp_code = es.chief_emp_code
			WHERE
				(
					es.emp_first_name LIKE '%{$request->emp_name}%'
					OR es.emp_last_name LIKE '%{$request->emp_name}%'
					OR es.emp_code LIKE '%{$request->emp_name}%'
				)
			LIMIT 10
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

	public function stage(Request $request) {
        if(empty($request->stage_id)) {
            $current_stage = DB::select("
                SELECT stage_id, stage_name, role_id
                from stage
                where stage_id = '1'
            "); 

        } else {
            $current_stage = DB::select("
                SELECT stage_id, stage_name, role_id
                from stage
                where stage_id = {$request->stage_id}
            "); 
        }

        $workflow_stage = DB::select("
            select to_stage_id, status
            from workflow_stage
            where from_stage_id = ?
        ",array($current_stage[0]->stage_id));
        
        if (empty($workflow_stage)) {
            return response()->json(['current_stage' => $current_stage, 'to_stage' => []]);
        }
        
        $actions = DB::select("
            SELECT s.stage_id, s.stage_name, s.stage_name as status
            from stage s
            where s.stage_id in ({$workflow_stage[0]->to_stage_id})
            order by s.stage_id
        ");
        
        return response()->json(['current_stage' => $current_stage, 'to_stage' =>$actions]);
    }

	public function cust(Request $request) {
		$items = DB::select("
			SELECT qdh.data_header_id, qdd.customer_id, c.customer_name, SUM(qdd.score) qdd.score, qdd.section_id 
			FROM questionaire_data_detail qdd
			LEFT JOIN customer c ON c.customer_id = qdd.customer_id
			LEFT JOIN questionaire_data_header qdh ON qdh.data_header_id = qdd.data_header_id 
			WHERE qdd.section_id = '{$request->section_id}'
			AND c.position_code = '{$request->position_code}'
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

	public function show_cust(Request $request) {
		try {
			$items = QuestionaireDataHeader::findOrFail($request->data_header_id);
		} catch (ModelNotFoundException $e) {
			return response()->json(['status' => 404, 'data' => 'QuestionaireDataHeader not found.']);
		}

		$qdd = DB::select("
			SELECT qdd.question_id, 
					q.answer_type_id, 
					q.parent_question_id, 
					q.question_name, 
					c.customer_id, 
					c.customer_name
			FROM questionaire_data_detail qdd
			LEFT JOIN customer c ON c.customer_id = qdd.customer_id
			LEFT JOIN question q ON q.question_id = qdd.question_id
			WHERE qdd.data_header_id = '{$request->data_header_id}'
			AND qdd.customer_id = '{$request->customer_id}'
		");

		foreach ($qdd as $qdd_k => $qdd_v) {
			$qdd['answer'] = DB::select("
				SELECT answer_id, answer_name, score, is_not_applicable
				FROM answer
				WHERE question_id = '".$qdd_v['question_id']."'
			");
		}

		return response()->json($qdd);
	}

	public function index(Request $request) {
		$between_date = "
			WHERE qdh.questionaire_date = (
				SELECT MAX(qdhh.questionaire_date) questionaire_date
				FROM questionaire_data_header qdhh
				WHERE qdhh.data_header_id = qdh.data_header_id
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

		$questionaire = empty($request->questionaire_id) ? "" : "AND qdh.questionaire_id = '{$request->questionaire_id}'";
		$emp_snapshot_id = empty($request->emp_snapshot_id) ? "" : "AND qdh.emp_snapshot_id = '{$request->emp_snapshot_id}'";

		$items = DB::select("
			SELECT qdh.data_header_id, p.position_code, CONCAT(es.emp_first_name, '', es.emp_last_name) emp_name
			FROM questionaire_data_header qdh
			LEFT JOIN employee_snapshot es ON es.emp_snapshot_id = qdh.emp_snapshot_id
			LEFT JOIN position p ON p.position_id = es.position_id
			LEFT JOIN questionaire qn ON qn.questionaire_id = qdh.questionaire_id
			".$between_date."
			".$questionaire."
			".$emp_snapshot_id."
			ORDER BY qdh.questionaire_date
			");

		// Get the current page from the url if it's not set default to 1
		empty($request->page) ? $page = 1 : $page = $request->page;

		// Number of items per page
		empty($request->rpp) ? $perPage = 10 : $perPage = $request->rpp;

		$offSet = ($page * $perPage) - $perPage; // Start displaying items from this number

		// Get only the items you need using array_slice (only get 10 items since that's what you need)
		$itemsForCurrentPage = array_slice($items, $offSet, $perPage, false);

		// Return the paginator with only 10 items but with the count of all items and set the it on the correct page
		$result = new LengthAwarePaginator($itemsForCurrentPage, count($items), $perPage, $page);

		return response()->json($result);
	}

	public function assign_template(Request $request) {

		if(!empty($request->data_header_id)) {
			try {
				QuestionaireDataHeader::findOrFail($request->data_header_id);
			} catch (ModelNotFoundException $e) {
				return response()->json(['status' => 404, 'data' => 'QuestionaireDataHeader not found.']);
			}

			$sub_items = DB::select("
				SELECT qdd.section_id, qs.section_name, qs.is_cust_search
				FROM questionaire_data_detail qdd
				LEFT JOIN questionaire_section qs ON qs.section_id = qdd.section_id
				WHERE qdd.data_header_id = '{$request->data_header_id}'
			");

			foreach ($sub_items as $key => $v_qdd) {
				$sub_items[$key]->sub_section = DB::select("
					SELECT q.question_id, q.answer_type_id, at.is_show_comment, q.parent_question_id, q.question_name, q.pass_score
					FROM questionaire_data_detail qdd
					LEFT JOIN question q ON q.question_id = qdd.question_id
					LEFT JOIN answer_type at ON at.answer_type_id = q.answer_type_id
					WHERE q.section_id = {$v_qdd->section_id}
					AND q.parent_question_id IS NULL
				");

				foreach ($sub_items[$key]->sub_section as $key2 => $anv) {
					$sub_items[$key]->sub_section[$key2]->answer =  DB::select("
						SELECT answer_id, row_name, answer_name, score, is_not_applicable
						FROM answer
						WHERE question_id = {$anv->question_id}
						");
				}

				foreach ($sub_items[$key]->sub_section as $key2 => $anv) {
					$sub_items[$key]->sub_section[$key2]->question =  DB::select("
						SELECT question_id, answer_type_id, parent_question_id, question_name
						FROM question
						WHERE parent_question_id = {$anv->question_id}
						");

					foreach ($sub_items[$key]->sub_section[$key2]->question as $key3 => $ssq) {
						$sub_items[$key]->sub_section[$key2]->question[$key3]->answer =  DB::select("
							SELECT answer_id, row_name, answer_name, score, is_not_applicable
							FROM answer
							WHERE question_id = {$ssq->question_id}
							");
					}
				}
			}

			$stage = DB::select("SELECT * FROM questionaire_data_stage WHERE data_header_id = '{$request->data_header_id}'");

		} else if(!empty($request->questionaire_id)) {
			try {
				QuestionaireSection::where('questionaire_id', '=', $request->questionaire_id)->firstOrFail();
			} catch (ModelNotFoundException $e) {
				return response()->json(['status' => 404, 'data' => 'QuestionaireSection not found.']);
			}

			$sub_items = DB::select("
				SELECT section_id, section_name, is_cust_search
				FROM questionaire_section
				WHERE questionaire_id = '{$request->questionaire_id}'
				");
			foreach ($sub_items as $key => $qsv) {
				$sub_items[$key]->sub_section =  DB::select("
					SELECT q.question_id, q.answer_type_id, at.is_show_comment, q.parent_question_id, q.question_name, q.pass_score
					FROM question q
					LEFT JOIN answer_type at ON at.answer_type_id = q.answer_type_id
					WHERE q.section_id = {$qsv->section_id}
					AND q.parent_question_id IS NULL
					");

				foreach ($sub_items[$key]->sub_section as $key2 => $anv) {
					$sub_items[$key]->sub_section[$key2]->answer =  DB::select("
						SELECT answer_id, row_name, answer_name, score, is_not_applicable
						FROM answer
						WHERE question_id = {$anv->question_id}
						");
				}

				foreach ($sub_items[$key]->sub_section as $key2 => $anv) {
					$sub_items[$key]->sub_section[$key2]->question =  DB::select("
						SELECT question_id, answer_type_id, parent_question_id, question_name
						FROM question
						WHERE parent_question_id = {$anv->question_id}
						");

					foreach ($sub_items[$key]->sub_section[$key2]->question as $key3 => $ssq) {
						$sub_items[$key]->sub_section[$key2]->question[$key3]->answer =  DB::select("
							SELECT answer_id, row_name, answer_name, score, is_not_applicable
							FROM answer
							WHERE question_id = {$ssq->question_id}
							");
					}
				}
			}

			$stage = [];
		} else {
			$sub_items = [];
			$stage = [];
		}

		return response()->json(['data' => $sub_items, 'stage' => $stage]);
	}

	public function store(Request $request) {
		$is_emp = DB::select("
			SELECT emp_snapshot_id
			FROM employee_snapshot
			WHERE emp_code = '".Auth::id()."'
		");
		
		DB::beginTransaction();
		$errors = [];
		$errors_validator = [];
		$validator = Validator::make([
			'questionaire_id' => $request->questionaire_id,
			'questionaire_date' => $request->questionaire_date,
			'emp_snapshot_id' => $request->emp_snapshot_id,
			'pass_score' => $request->pass_score,
			'total_score' => $request->total_score,
			'data_stage_id' => $request->data_stage_id
		], [
			'questionaire_id' => 'required|integer',
			'questionaire_date' => 'required|date|date_format:Y-m-d',
			'emp_snapshot_id' => 'required|integer',
			'pass_score' => 'required|integer',
			'total_score' => 'required|integer',
			'data_stage_id' => 'required|integer'
		]);

		if($validator->fails()) {
			$errors_validator[] = $validator->errors();
		}

		if(!empty($request['detail'])) {
			foreach ($request['detail'] as $d) {
				$validator_detail = Validator::make([
					'section_id' => $d['section_id'],
					'question_id' => $d['question_id'],
					'score' => $d['score'],
					'is_not_applicable' => $d['is_not_applicable'],
					'desc_answer' => $d['desc_answer'],
				], [
					'section_id' => 'required|integer',
					'question_id' => 'required|integer',
					'score' => 'required|integer',
					'is_not_applicable' => 'required|integer',
					'desc_answer' => 'required'
				]);

				if($validator_detail->fails()) {
					$errors_validator[] = $validator_detail->errors();
				}
			}
		}

		$validator_stage = Validator::make([
			'from_stage_id' => $request['stage']->from_stage_id,
			'to_emp_snapshot_id' => $request['stage']->to_emp_snapshot_id,
			'to_stage_id' => $request['stage']->to_stage_id,
			'status' => $request['stage']->status,
			'remark' => $request['stage']->remark
		], [
			'from_stage_id' => 'required|integer',
			'to_emp_snapshot_id' => 'required|integer',
			'to_stage_id' => 'required|integer',
			'status' => 'required|max:50',
			'remark' => 'required|max:1000'
		]);

		if($validator_stage->fails()) {
			$errors_validator[] = $validator_stage->errors();
		}

		if(!empty($errors_validator)) {
			return response()->json(['status' => 400, 'errors' => $errors_validator]);
		}

		$h = new QuestionaireDataHeader;
		$h->questionaire_id = $request->questionaire_id;
		$h->questionaire_date = $request->questionaire_date;
		$h->emp_snapshot_id = $request->emp_snapshot_id;
		$h->pass_score = $request->pass_score;
		$h->total_score = $request->total_score;
		$h->data_stage_id = $request->data_stage_id;
		$h->created_by = Auth::id();
		$h->updated_by = Auth::id();
		try {
			$h->save();
			if(!empty($request['detail'])) {
				foreach ($request['detail'] as $keyD => $d) {
					$dt = new QuestionaireDataDetail;
					$dt->data_header_id = $h->data_header_id;
					$dt->section_id = $d['section_id'];
					$dt->question_id = $d['question_id'];
					$dt->score = $d['score'];
					$dt->is_not_applicable = $d['is_not_applicable'];
					$dt->desc_answer = $d['desc_answer'];
					$dt->created_by = Auth::id();
					$dt->updated_by = Auth::id();
					try {
						$dt->save();
					} catch (Exception $e) {
						return response()->json(['status' => 400, 'errors' => ['QuestionaireDataDetail' => $e]]);
					}
				}
			}

			$s = new QuestionaireDataStage;
			$s->data_header_id = $h->data_header_id;
			$s->from_emp_snapshot_id = $is_emp[0]->emp_snapshot_id;
			$s->from_stage_id = $request['stage']->from_stage_id;
			$s->to_emp_snapshot_id = $request['stage']->to_emp_snapshot_id;
			$s->to_stage_id = $request['stage']->to_stage_id;
			$s->status = $request['stage']->status;
			$s->remark = $request['stage']->remark;
			$s->created_by = Auth::id();
			try {
				$s->save();
			} catch (Exception $e) {
				$errors[] = ['QuestionaireDataStage' => $e];
			}
		} catch (Exception $e) {
			$errors[] = ['QuestionaireDataHeader' => $e];
		}

		empty($errors) ? DB::commit() : DB::rollback();
		empty($errors) ? $status = 200 : $status = 400;
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

		Config::set('mail.driver',$config->mail_driver);
		Config::set('mail.host',$config->mail_host);
		Config::set('mail.port',$config->mail_port);
		Config::set('mail.encryption',$config->mail_encryption);
		Config::set('mail.username',$config->mail_username);
		Config::set('mail.password',$config->mail_password);
		$from = Config::get('mail.from');

		$is_emp = DB::select("
			SELECT emp_snapshot_id
			FROM employee_snapshot
			WHERE emp_code = '".Auth::id()."'
		");
		
		DB::beginTransaction();
		$errors = [];
		$errors_validator = [];
		$validator = Validator::make([
			'questionaire_id' => $request->questionaire_id,
			'questionaire_date' => $request->questionaire_date,
			'emp_snapshot_id' => $request->emp_snapshot_id,
			'pass_score' => $request->pass_score,
			'total_score' => $request->total_score,
			'data_stage_id' => $request->data_stage_id
		], [
			'questionaire_id' => 'required|integer',
			'questionaire_date' => 'required|date|date_format:Y-m-d',
			'emp_snapshot_id' => 'required|integer',
			'pass_score' => 'required|integer',
			'total_score' => 'required|integer',
			'data_stage_id' => 'required|integer'
		]);

		if($validator->fails()) {
			$errors_validator[] = $validator->errors();
		}

		if(!empty($request['detail'])) {
			foreach ($request['detail'] as $d) {
				$validator_detail = Validator::make([
					'data_detail_id' => $d['data_detail_id'],
					'section_id' => $d['section_id'],
					'question_id' => $d['question_id'],
					'score' => $d['score'],
					'is_not_applicable' => $d['is_not_applicable'],
					'desc_answer' => $d['desc_answer'],
				], [
					'data_detail_id' => 'required|integer',
					'section_id' => 'required|integer',
					'question_id' => 'required|integer',
					'score' => 'required|integer',
					'is_not_applicable' => 'required|integer',
					'desc_answer' => 'required'
				]);

				if($validator_detail->fails()) {
					$errors_validator[] = $validator_detail->errors();
				}
			}
		}

		$validator_stage = Validator::make([
			'from_stage_id' => $request['stage']->from_stage_id,
			'to_emp_snapshot_id' => $request['stage']->to_emp_snapshot_id,
			'to_stage_id' => $request['stage']->to_stage_id,
			'status' => $request['stage']->status,
			'remark' => $request['stage']->remark
		], [
			'from_stage_id' => 'required|integer',
			'to_emp_snapshot_id' => 'required|integer',
			'to_stage_id' => 'required|integer',
			'status' => 'required|max:50',
			'remark' => 'required|max:1000'
		]);

		if($validator_stage->fails()) {
			$errors_validator[] = $validator_stage->errors();
		}

		if(!empty($errors_validator)) {
			return response()->json(['status' => 400, 'errors' => $errors_validator]);
		}

		$h->questionaire_id = $request->questionaire_id;
		$h->questionaire_date = $request->questionaire_date;
		$h->emp_snapshot_id = $request->emp_snapshot_id;
		$h->pass_score = $request->pass_score;
		$h->total_score = $request->total_score;
		$h->data_stage_id = $request->data_stage_id;
		$h->updated_by = Auth::id();
		try {
			$h->save();
			if(!empty($request['detail'])) {
				foreach ($request['detail'] as $keyD => $d) {
					$dt = QuestionaireDataDetail::find($d['data_detail_id']);
					$dt->data_header_id = $h->data_header_id;
					$dt->section_id = $d['section_id'];
					$dt->question_id = $d['question_id'];
					$dt->score = $d['score'];
					$dt->is_not_applicable = $d['is_not_applicable'];
					$dt->desc_answer = $d['desc_answer'];
					$dt->created_by = Auth::id();
					$dt->updated_by = Auth::id();
					try {
						$dt->save();
					} catch (Exception $e) {
						return response()->json(['status' => 400, 'errors' => ['QuestionaireDataDetail' => $e]]);
					}
				}
			}

			$s = new QuestionaireDataStage;
			$s->data_header_id = $h->data_header_id;
			$s->from_emp_snapshot_id = $is_emp[0]->emp_snapshot_id;
			$s->from_stage_id = $request['stage']->from_stage_id;
			$s->to_emp_snapshot_id = $request['stage']->to_emp_snapshot_id;
			$s->to_stage_id = $request['stage']->to_stage_id;
			$s->status = $request['stage']->status;
			$s->remark = $request['stage']->remark;
			$s->created_by = Auth::id();
			try {
				$s->save();
			} catch (Exception $e) {
				$errors[] = ['QuestionaireDataStage' => $e];
			}
		} catch (Exception $e) {
			$errors[] = ['QuestionaireDataHeader' => $e];
		}

		if ($config->email_reminder_flag == 1) {
			try {
				// $emp_snap = EmployeeSnapshot::find($request['stage']->to_emp_snapshot_id);

				// $data = ["chief_emp_name" => $chief_emp->emp_name, "emp_name" => $employee->emp_name, "status" => $stage->status, "web_domain" => $config->web_domain, 'emp_result_id' => $emp_result->emp_result_id, 'appraisal_type_id' => $emp_result->appraisal_type_id, 'assignment_flag' => $stage->assignment_flag];
				// $to = [$emp_snap->email];

				// Mail::send('emails.status', $data, function($message) use ($from, $to)
				// {
				// 	$message->from($from['address'], $from['name']);
				// 	$message->to($to)->subject('ระบบได้ทำการประเมิน');
				// });
			} catch (Exception $ExceptionError) {
				$errors[] = ['mail error' => $ExceptionError];
			}
		}

		empty($errors) ? DB::commit() : DB::rollback();
		empty($errors) ? $status = 200 : $status = 400;
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

	// public function destroy_data_detail($data_detail_id) {
	// 	try {
	// 		$item = QuestionaireDataDetail::findOrFail($data_detail_id);
	// 	} catch (ModelNotFoundException $e) {
	// 		return response()->json(['status' => 404, 'data' => 'QuestionaireDataDetail not found.']);
	// 	}

	// 	try {
	// 		$item->delete();
	// 	} catch (Exception $e) {
	// 		if ($e->errorInfo[1] == 1451) {
	// 			return response()->json(['status' => 400, 'data' => 'Cannot delete because this QuestionaireDataDetail is in use.']);
	// 		} else {
	// 			return response()->json($e->errorInfo);
	// 		}
	// 	}

	// 	return response()->json(['status' => 200]);
	// }
}
