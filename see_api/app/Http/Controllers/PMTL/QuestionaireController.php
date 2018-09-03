<?php

namespace App\Http\Controllers\PMTL;

use App\Questionaire;
use App\QuestionaireSection;
use App\Question;
use App\Answer;

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

class QuestionaireController extends Controller
{

	public function __construct()
	{
	   $this->middleware('jwt.auth');
	}

	public function list_type() {
		$items = DB::select("
			SELECT questionaire_type_id, questionaire_type
			FROM questionaire_type
			ORDER BY questionaire_type_id
		");
		return response()->json($items);
	}

	public function auto_name(Request $request) {
		$questionaire_type_id = empty($request->questionaire_type_id) ? "" : "AND questionaire_type_id = '{$request->questionaire_type_id}'";

		$items = DB::select("
			SELECT questionaire_id, questionaire_name
			FROM questionaire
			WHERE questionaire_name LIKE '%{$request->questionaire_name}%'
			".$questionaire_type_id."
			AND is_active = 1
			ORDER BY questionaire_id
		");
		return response()->json($items);
	}

	public function index(Request $request)
	{
		$questionaire_type_id = empty($request->questionaire_type_id) ? "" : "AND q.questionaire_type_id = '{$request->questionaire_type_id}'";
		$questionaire_id = empty($request->questionaire_id) ? "" : "AND q.questionaire_id = '{$request->questionaire_id}'";

		$items = DB::select("
			SELECT q.questionaire_id, 
					qt.questionaire_type,
					q.questionaire_name,
					q.pass_score
			FROM questionaire q
			LEFT OUTER JOIN questionaire_type qt ON qt.questionaire_type_id = q.questionaire_type_id
			WHERE q.is_active = 1
			".$questionaire_type_id."
			".$questionaire_id."
			ORDER BY qt.questionaire_type, q.questionaire_id
		");

		return response()->json($items);
	}

	public function show($questionaire_id)
	{
		try {
			$items = Questionaire::findOrFail($questionaire_id);
		} catch (ModelNotFoundException $e) {
			return response()->json(['status' => 404, 'data' => 'Questionaire not found.']);
		}

		$sub_items = DB::select("
			SELECT section_id, section_name, is_cust_search
			FROM questionaire_section
			WHERE questionaire_id = {$questionaire_id}
			");
		foreach ($sub_items as $key => $qsv) {
			$sub_items[$key]->question =  DB::select("
				SELECT question_id, answer_type_id, parent_question_id, question_name
				FROM question
				WHERE section_id = {$qsv->section_id}
				");

			foreach ($sub_items[$key]->question as $key2 => $anv) {
				$sub_items[$key]->question[$key2]->answer =  DB::select("
					SELECT answer_id, row_name, answer_name, score
					FROM answer
					WHERE question_id = {$anv->question_id}
					");
			}
		}

		return response()->json(['questionaire' => $items, 'questionaire_section' => $sub_items]);
	}

	public function store(Request $request)
	{
		$errors = array();
		$errors_validator = array();
		DB::beginTransaction();

		$validator = Validator::make($request, [
			'questionaire_name' => 'required|max:255',
			'questionaire_type_id' => 'required|integer',
			'pass_score' => 'required|integer',
			'is_active' => 'required|integer'
		]);

		if(!empty($request['questionaire_section'])) {
			foreach ($request['questionaire_section'] as $qs) {
				$validator_questionaire_section = Validator::make($qs, [
					'section_name' => 'required|max:255',
					'is_cust_search' => 'required|integer'
				]);
				$errors_validator[] = ($validator_questionaire_section->false()) ? $validator_questionaire_section->errors(): '';
			}
		}

		if(!empty($request['questionaire_section']['question'])) {
			foreach ($request['questionaire_section']['question'] as $q) {
				$validator_question = Validator::make($q, [
					'answer_type_id' => 'required|integer',
					'question_name' => 'required|max:255'
				]);
				$errors_validator[] = ($validator_question->false()) ? $validator_question->errors(): '';
			}
		}

		if(!empty($request['questionaire_section']['question']['answer'])) {
			foreach ($request['questionaire_section']['question']['answer'] as $an) {
				$validator_answer = Validator::make($an, [
					'row_name' => 'required|max:255',
					'answer_name' => 'required|max:255',
					'score' => 'required|integer'
				]);
				$errors_validator[] = ($validator_answer->false()) ? $validator_answer->errors(): '';
			}
		}

		if(!empty($errors_validator)) {
			return response()->json(['status' => 400, 'errors' => $errors_validator]);
		}

		$qn = new Questionaire;
		$qn->questionaire_name = $request->questionaire_name;
		$qn->questionaire_type_id = $request->questionaire_type_id;
		$qn->pass_score = $request->pass_score;
		$qn->is_active = $request->is_active;
		$qn->created_by = Auth::id();
		$qn->updated_by = Auth::id();
		try {
			$qn->save();
			if(!empty($request['questionaire_section'])) {
				foreach ($request['questionaire_section'] as $qsv) {
					$qs = new QuestionaireSection;
					$qs->questionaire_id = $qn->questionaire_id;
					$qs->section_name = $qsv['section_name'];
					$qs->is_cust_search = $qsv['is_cust_search'];
					$qs->created_by = Auth::id();
					$qs->updated_by = Auth::id();
					try {
						$qs->save();
						if(!empty($request['questionaire_section']['question'])) {
							foreach ($request['questionaire_section']['question'] as $qv) {
								$q = new Question;
								$q->section_id = $qs->section_id;
								$q->answer_type_id = $qv['answer_type_id'];
								$q->parent_question_id = $qv['parent_question_id'];
								$q->question_name = $qv['question_name'];
								$q->created_by = Auth::id();
								$q->updated_by = Auth::id();
								try {
									$q->save();
									if(!empty($request['questionaire_section']['question']['answer'])) {
										foreach ($request['questionaire_section']['question']['answer'] as $ans) {
											$an = new Answer;
											$an->question_id = $q->question_id;
											$an->row_name = $ans['row_name'];
											$an->answer_name = $ans['answer_name'];
											$an->score = $ans['score'];
											$an->created_by = Auth::id();
											$an->updated_by = Auth::id();
											try {
												$an->save();
											} catch (Exception $e) {
												$errors[] = ['Answer' => $e];
											}
										}
									}
								} catch (Exception $e) {
									$errors[] = ['Question' => $e];
								}
							}
						}
					} catch (Exception $e) {
						$errors[] = ['QuestionaireSection' => $e];
					}
				}
			}
		} catch (Exception $e) {
			$errors[] = ['Questionaire' => $e];
		}

		if(empty($errors)) {
			DB::commit();
			$status = 200;
		} else {
			DB::rollback();
			$status = 400;
		}

		return response()->json(['status' => $status, 'errors' => $errors]);
	}

	public function update(Request $request)
	{
		$errors = array();
		$errors_validator = array();
		DB::beginTransaction();

		$validator = Validator::make($request, [
			'questionaire_id' => 'required|integer',
			'questionaire_name' => 'required|max:255',
			'questionaire_type_id' => 'required|integer',
			'pass_score' => 'required|integer',
			'is_active' => 'required|integer'
		]);

		if(!empty($request['questionaire_section'])) {
			foreach ($request['questionaire_section'] as $qs) {
				$validator_questionaire_section = Validator::make($qs, [
					'section_id' => 'required|integer',
					'section_name' => 'required|max:255',
					'is_cust_search' => 'required|integer'
				]);
				$errors_validator[] = ($validator_questionaire_section->false()) ? $validator_questionaire_section->errors(): '';
			}
		}

		if(!empty($request['questionaire_section']['question'])) {
			foreach ($request['questionaire_section']['question'] as $q) {
				$validator_question = Validator::make($q, [
					'question_id' => 'required|integer',
					'answer_type_id' => 'required|integer',
					'question_name' => 'required|max:255'
				]);
				$errors_validator[] = ($validator_question->false()) ? $validator_question->errors(): '';
			}
		}

		if(!empty($request['questionaire_section']['question']['answer'])) {
			foreach ($request['questionaire_section']['question']['answer'] as $an) {
				$validator_answer = Validator::make($an, [
					'answer_id' => 'required|integer',
					'row_name' => 'required|max:255',
					'answer_name' => 'required|max:255',
					'score' => 'required|integer'
				]);
				$errors_validator[] = ($validator_answer->false()) ? $validator_answer->errors(): '';
			}
		}

		if(!empty($errors_validator)) {
			return response()->json(['status' => 400, 'errors' => $errors_validator]);
		}

		$qn = Questionaire::find($request->questionaire_id);
		$qn->questionaire_name = $request->questionaire_name;
		$qn->questionaire_type_id = $request->questionaire_type_id;
		$qn->pass_score = $request->pass_score;
		$qn->is_active = $request->is_active;
		$qn->updated_by = Auth::id();
		try {
			$qn->save();
			if(!empty($request['questionaire_section'])) {
				foreach ($request['questionaire_section'] as $qsv) {
					$qs = QuestionaireSection::find($qsv['section_id']);
					$qs->questionaire_id = $qn->questionaire_id;
					$qs->section_name = $qsv['section_name'];
					$qs->is_cust_search = $qsv['is_cust_search'];
					$qs->updated_by = Auth::id();
					try {
						$qs->save();
						if(!empty($request['questionaire_section']['question'])) {
							foreach ($request['questionaire_section']['question'] as $qv) {
								$q = Question::find($qv['question_id']);
								$q->section_id = $qs->section_id;
								$q->answer_type_id = $qv['answer_type_id'];
								$q->parent_question_id = $qv['parent_question_id'];
								$q->question_name = $qv['question_name'];
								$q->updated_by = Auth::id();
								try {
									$q->save();
									if(!empty($request['questionaire_section']['question']['answer'])) {
										foreach ($request['questionaire_section']['question']['answer'] as $ans) {
											$an = Answer::find($ans['answer_id']);
											$an->question_id = $q->question_id;
											$an->row_name = $ans['row_name'];
											$an->answer_name = $ans['answer_name'];
											$an->score = $ans['score'];
											$an->updated_by = Auth::id();
											try {
												$an->save();
											} catch (Exception $e) {
												$errors[] = ['Answer' => $e];
											}
										}
									}
								} catch (Exception $e) {
									$errors[] = ['Question' => $e];
								}
							}
						}
					} catch (Exception $e) {
						$errors[] = ['QuestionaireSection' => $e];
					}
				}
			}
		} catch (Exception $e) {
			$errors[] = ['Questionaire' => $e];
		}

		if(empty($errors)) {
			DB::commit();
			$status = 200;
		} else {
			DB::rollback();
			$status = 400;
		}

		return response()->json(['status' => $status, 'errors' => $errors]);
	}

	public function destroy($questionaire_id)
	{
		try {
			$item = Questionaire::findOrFail($questionaire_id);
		} catch (ModelNotFoundException $e) {
			return response()->json(['status' => 404, 'data' => 'Questionaire not found.']);
		}

		try {
			$item->delete();
		} catch (Exception $e) {
			if ($e->errorInfo[1] == 1451) {
				return response()->json(['status' => 400, 'data' => 'Cannot delete because this Questionaire is in use.']);
			} else {
				return response()->json($e->errorInfo);
			}
		}

		return response()->json(['status' => 200]);

	}

	public function destroy_section($section_id)
	{
		try {
			$item = QuestionaireSection::findOrFail($section_id);
		} catch (ModelNotFoundException $e) {
			return response()->json(['status' => 404, 'data' => 'QuestionaireSection not found.']);
		}

		try {
			$item->delete();
		} catch (Exception $e) {
			if ($e->errorInfo[1] == 1451) {
				return response()->json(['status' => 400, 'data' => 'Cannot delete because this QuestionaireSection is in use.']);
			} else {
				return response()->json($e->errorInfo);
			}
		}

		return response()->json(['status' => 200]);

	}

	public function destroy_question($question_id)
	{
		try {
			$item = Question::findOrFail($question_id);
		} catch (ModelNotFoundException $e) {
			return response()->json(['status' => 404, 'data' => 'Question not found.']);
		}

		try {
			$item->delete();
		} catch (Exception $e) {
			if ($e->errorInfo[1] == 1451) {
				return response()->json(['status' => 400, 'data' => 'Cannot delete because this Question is in use.']);
			} else {
				return response()->json($e->errorInfo);
			}
		}

		return response()->json(['status' => 200]);

	}

	public function destroy_answer($answer_id)
	{
		try {
			$item = Answer::findOrFail($answer_id);
		} catch (ModelNotFoundException $e) {
			return response()->json(['status' => 404, 'data' => 'Answer not found.']);
		}

		try {
			$item->delete();
		} catch (Exception $e) {
			if ($e->errorInfo[1] == 1451) {
				return response()->json(['status' => 400, 'data' => 'Cannot delete because this Answer is in use.']);
			} else {
				return response()->json($e->errorInfo);
			}
		}

		return response()->json(['status' => 200]);

	}
}
