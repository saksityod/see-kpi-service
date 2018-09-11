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

	public function list_answer_type() {
		$items = DB::select("
			SELECT *
			FROM answer_type
			ORDER BY answer_type_id
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
			INNER JOIN questionaire_type qt ON qt.questionaire_type_id = q.questionaire_type_id
			".$questionaire_type_id."
			".$questionaire_id."
			ORDER BY qt.questionaire_type, q.questionaire_id
		");

		return response()->json($items);
	}

	public function show($questionaire_id) {
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
			$sub_items[$key]->sub_section =  DB::select("
				SELECT q.question_id, q.answer_type_id, at.is_show_comment, q.parent_question_id, q.question_name, q.pass_score
				FROM question q
				LEFT JOIN answer_type at ON at.answer_type_id = q.answer_type_id
				WHERE q.section_id = '{$qsv->section_id}'
				AND q.parent_question_id IS NULL
				");

			foreach ($sub_items[$key]->sub_section as $key2 => $anv) {
				$sub_items[$key]->sub_section[$key2]->answer =  DB::select("
					SELECT answer_id, row_name, answer_name, score, is_not_applicable
					FROM answer
					WHERE question_id = '{$anv->question_id}'
					");
			}

			foreach ($sub_items[$key]->sub_section as $key2 => $anv) {
				$sub_items[$key]->sub_section[$key2]->question =  DB::select("
					SELECT question_id, answer_type_id, parent_question_id, question_name
					FROM question
					WHERE parent_question_id = '{$anv->question_id}'
					");

				foreach ($sub_items[$key]->sub_section[$key2]->question as $key3 => $ssq) {
					$sub_items[$key]->sub_section[$key2]->question[$key3]->answer =  DB::select("
						SELECT answer_id, row_name, answer_name, score, is_not_applicable
						FROM answer
						WHERE question_id = '{$ssq->question_id}'
						");
				}
			}
		}

		return response()->json(['head' => $items, 'questionaire_section' => $sub_items]);
	}

	public function store(Request $request) {
		$errors_validator = [];
		$validator = Validator::make([
			'questionaire_name' => $request->questionaire_name,
			'questionaire_type_id' => $request->questionaire_type_id,
			'pass_score' => $request->pass_score,
			'is_active' => $request->is_active
		], [
			'questionaire_name' => 'required|max:255',
			'questionaire_type_id' => 'required|integer',
			'pass_score' => 'required|between:0,99.99',
			'is_active' => 'required|integer'
		]);

		if($validator->fails()) {
			$errors_validator[] = $validator->errors();
		}

		if(!empty($request['questionaire_section'])) {
			foreach ($request['questionaire_section'] as $qs_k => $qs) {
				$validator_questionaire_section = Validator::make([
					'section_name' => $qs['section_name'],
					'is_cust_search' => $qs['is_cust_search']
				], [
					'section_name' => 'required|max:255',
					'is_cust_search' => 'required|integer'
				]);

				if($validator_questionaire_section->fails()) {
					$errors_validator[] = $validator_questionaire_section->errors();
				}

				if(!empty($request['questionaire_section'][$qs_k]['sub_section'])) {
					foreach ($request['questionaire_section'][$qs_k]['sub_section'] as $ss_key => $ss) {
						$validator_sub_section = Validator::make([
							'answer_type_id' => $ss['answer_type_id'],
							'question_name' => $ss['question_name']
						], [
							'answer_type_id' => 'required|integer',
							'question_name' => 'required|max:255'
						]);

						if($validator_sub_section->fails()) {
							$errors_validator[] = $validator_sub_section->errors();
						}

						if(!empty($request['questionaire_section'][$qs_k]['sub_section'][$ss_key]['answer'])) {
							foreach ($request['questionaire_section'][$qs_k]['sub_section'][$ss_key]['answer'] as $an) {
								$validator_s_answer = Validator::make([
									'row_name' => $an['row_name'],
									'answer_name' => $an['answer_name'],
									'is_not_applicable' => $an['is_not_applicable'],
									'score' => $an['score']
								], [
									'row_name' => 'required|max:255',
									'answer_name' => 'required|max:255',
									'is_not_applicable' => 'required|integer',
									'score' => 'required|between:0,99.99'
								]);

								if($validator_s_answer->fails()) {
									$errors_validator[] = $validator_s_answer->errors();
								}
							}
						} else if(!empty($request['questionaire_section'][$qs_k]['sub_section'][$ss_key]['question'])) {
							foreach ($request['questionaire_section'][$qs_k]['sub_section'][$ss_key]['question'] as $q_k => $q) {
								$validator_question = Validator::make([
									'answer_type_id' => $q['answer_type_id'],
									'question_name' => $q['question_name']
								], [
									'answer_type_id' => 'required|integer',
									'question_name' => 'required|max:255'
								]);

								if($validator_question->fails()) {
									$errors_validator[] = $validator_question->errors();
								}

								try {
									foreach ($request['questionaire_section'][$qs_k]['sub_section'][$ss_key]['question'][$q_k]['answer'] as $an) {
										$validator_answer = Validator::make([
											'row_name' => $an['row_name'],
											'answer_name' => $an['answer_name'],
											'is_not_applicable' => $an['is_not_applicable'],
											'score' => $an['score']
										], [
											'row_name' => 'required|max:255',
											'answer_name' => 'required|max:255',
											'is_not_applicable' => 'required|integer',
											'score' => 'required|between:0,99.99'
										]);

										if($validator_answer->fails()) {
											$errors_validator[] = $validator_answer->errors();
										}
									}

								} catch (Exception $e) {
									return response()->json(
										['status' => 400, 'errors' => ['data' => ['validate' => ['Please Add Question or Answer.']]]]
									);
								}
							}
						} else {
							return response()->json(
								['status' => 400, 'errors' => ['data' => ['validate' => ['Please Add Question or Answer.']]]]
							);
						}
					}
				}
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
		$qn->save();

		if(!empty($request['questionaire_section'])) {
			foreach ($request['questionaire_section'] as $qsv_k => $qsv) {
				$qs = new QuestionaireSection;
				$qs->questionaire_id = $qn->questionaire_id;
				$qs->section_name = $qsv['section_name'];
				$qs->is_cust_search = $qsv['is_cust_search'];
				$qs->created_by = Auth::id();
				$qs->updated_by = Auth::id();
				$qs->save();

				if(!empty($request['questionaire_section'][$qsv_k]['sub_section'])) {
					foreach ($request['questionaire_section'][$qsv_k]['sub_section'] as $qssv_k => $qssv) {
						$q = new Question;
						$q->section_id = $qs->section_id;
						$q->answer_type_id = $qssv['answer_type_id'];
						$q->pass_score = $qssv['pass_score'];
						$q->question_name = $qssv['question_name'];
						$q->created_by = Auth::id();
						$q->updated_by = Auth::id();
						$q->save();

						if(!empty($request['questionaire_section'][$qsv_k]['sub_section'][$qssv_k]['answer'])) {
							foreach ($request['questionaire_section'][$qsv_k]['sub_section'][$qssv_k]['answer'] as $ansv) {
								$an = new Answer;
								$an->question_id = $q->question_id;
								$an->row_name = $ansv['row_name'];
								$an->answer_name = $ansv['answer_name'];
								$an->is_not_applicable = $ansv['is_not_applicable'];
								$an->score = $ansv['score'];
								$an->created_by = Auth::id();
								$an->updated_by = Auth::id();
								$an->save();
							}
						}

						if(!empty($request['questionaire_section'][$qsv_k]['sub_section'][$qssv_k]['question'])) {
							foreach ($request['questionaire_section'][$qsv_k]['sub_section'][$qssv_k]['question'] as $qssq_k => $quesv) {
								$ques = new Question;
								$ques->section_id = $qs->section_id;
								$ques->answer_type_id = $quesv['answer_type_id'];
								$ques->parent_question_id = $q->question_id;
								$ques->question_name = $quesv['question_name'];
								$ques->created_by = Auth::id();
								$ques->updated_by = Auth::id();
								$ques->save();

								if(!empty($request['questionaire_section'][$qsv_k]['sub_section'][$qssv_k]['question'][$qssq_k]['answer'])) {
									foreach ($request['questionaire_section'][$qsv_k]['sub_section'][$qssv_k]['question'][$qssq_k]['answer'] as $ansv) {
										$an = new Answer;
										$an->question_id = $ques->question_id;
										$an->row_name = $ansv['row_name'];
										$an->answer_name = $ansv['answer_name'];
										$an->is_not_applicable = $ansv['is_not_applicable'];
										$an->score = $ansv['score'];
										$an->created_by = Auth::id();
										$an->updated_by = Auth::id();
										$an->save();
									}
								}
							}
						}
					}
				}
			}
		}

		return response()->json(['status' => 200]);
	}

	public function update(Request $request)
	{
		try {
			$item = Questionaire::findOrFail($request->questionaire_id);
		} catch (ModelNotFoundException $e) {
			return response()->json(['status' => 404, 'data' => 'Questionaire not found.']);
		}

		$errors_validator = [];
		$validator = Validator::make([
			'questionaire_name' => $request->questionaire_name,
			'questionaire_type_id' => $request->questionaire_type_id,
			'pass_score' => $request->pass_score,
			'is_active' => $request->is_active
		], [
			'questionaire_name' => 'required|max:255',
			'questionaire_type_id' => 'required|integer',
			'pass_score' => 'required|between:0,99.99',
			'is_active' => 'required|integer'
		]);

		if($validator->fails()) {
			$errors_validator[] = $validator->errors();
		}

		if(!empty($request['questionaire_section'])) {
			foreach ($request['questionaire_section'] as $qs_k => $qs) {
				$validator_questionaire_section = Validator::make([
					'section_name' => $qs['section_name'],
					'is_cust_search' => $qs['is_cust_search']
				], [
					'section_name' => 'required|max:255',
					'is_cust_search' => 'required|integer'
				]);

				if($validator_questionaire_section->fails()) {
					$errors_validator[] = $validator_questionaire_section->errors();
				}

				if(!empty($request['questionaire_section'][$qs_k]['sub_section'])) {
					foreach ($request['questionaire_section'][$qs_k]['sub_section'] as $ss_key => $ss) {
						$validator_sub_section = Validator::make([
							'answer_type_id' => $ss['answer_type_id'],
							'question_name' => $ss['question_name']
						], [
							'answer_type_id' => 'required|integer',
							'question_name' => 'required|max:255'
						]);

						if($validator_sub_section->fails()) {
							$errors_validator[] = $validator_sub_section->errors();
						}

						if(!empty($request['questionaire_section'][$qs_k]['sub_section'][$ss_key]['answer'])) {
							foreach ($request['questionaire_section'][$qs_k]['sub_section'][$ss_key]['answer'] as $an) {
								$validator_s_answer = Validator::make([
									'row_name' => $an['row_name'],
									'answer_name' => $an['answer_name'],
									'is_not_applicable' => $an['is_not_applicable'],
									'score' => $an['score']
								], [
									'row_name' => 'required|max:255',
									'answer_name' => 'required|max:255',
									'is_not_applicable' => 'required|integer',
									'score' => 'required|between:0,99.99'
								]);

								if($validator_s_answer->fails()) {
									$errors_validator[] = $validator_s_answer->errors();
								}
							}
						} else if(!empty($request['questionaire_section'][$qs_k]['sub_section'][$ss_key]['question'])) {
							foreach ($request['questionaire_section'][$qs_k]['sub_section'][$ss_key]['question'] as $q_k => $q) {
								$validator_question = Validator::make([
									'answer_type_id' => $q['answer_type_id'],
									'question_name' => $q['question_name']
								], [
									'answer_type_id' => 'required|integer',
									'question_name' => 'required|max:255'
								]);

								if($validator_question->fails()) {
									$errors_validator[] = $validator_question->errors();
								}

								try {
									foreach ($request['questionaire_section'][$qs_k]['sub_section'][$ss_key]['question'][$q_k]['answer'] as $an) {
										$validator_answer = Validator::make([
											'row_name' => $an['row_name'],
											'answer_name' => $an['answer_name'],
											'is_not_applicable' => $an['is_not_applicable'],
											'score' => $an['score']
										], [
											'row_name' => 'required|max:255',
											'answer_name' => 'required|max:255',
											'is_not_applicable' => 'required|integer',
											'score' => 'required|between:0,99.99'
										]);

										if($validator_answer->fails()) {
											$errors_validator[] = $validator_answer->errors();
										}
									}

								} catch (Exception $e) {
									return response()->json(
										['status' => 400, 'errors' => ['data' => ['validate' => ['Please Add Question or Answer.']]]]
									);
								}
							}
						} else {
							return response()->json(
								['status' => 400, 'errors' => ['data' => ['validate' => ['Please Add Question or Answer.']]]]
							);
						}
					}
				}
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
		$qn->created_by = Auth::id();
		$qn->updated_by = Auth::id();
		$qn->save();

		if(!empty($request['questionaire_section'])) {
			foreach ($request['questionaire_section'] as $qsv_k => $qsv) {
				$qs = empty($qsv['section_id']) ? new QuestionaireSection : QuestionaireSection::find($qsv['section_id']);
				$qs->questionaire_id = $qn->questionaire_id;
				$qs->section_name = $qsv['section_name'];
				$qs->is_cust_search = $qsv['is_cust_search'];
				$qs->created_by = Auth::id();
				$qs->updated_by = Auth::id();
				$qs->save();

				if(!empty($request['questionaire_section'][$qsv_k]['sub_section'])) {
					foreach ($request['questionaire_section'][$qsv_k]['sub_section'] as $qssv_k => $qssv) {
						$q = empty($qssv['question_id']) ? new Question : Question::find($qssv['question_id']);
						$q->section_id = $qs->section_id;
						$q->answer_type_id = $qssv['answer_type_id'];
						$q->pass_score = $qssv['pass_score'];
						$q->question_name = $qssv['question_name'];
						$q->created_by = Auth::id();
						$q->updated_by = Auth::id();
						$q->save();

						if(!empty($request['questionaire_section'][$qsv_k]['sub_section'][$qssv_k]['answer'])) {
							foreach ($request['questionaire_section'][$qsv_k]['sub_section'][$qssv_k]['answer'] as $ansv) {
								$an = empty($ansv['answer_id']) ? new Answer : Answer::find($ansv['answer_id']);
								$an->question_id = $q->question_id;
								$an->row_name = $ansv['row_name'];
								$an->answer_name = $ansv['answer_name'];
								$an->is_not_applicable = $ansv['is_not_applicable'];
								$an->score = $ansv['score'];
								$an->created_by = Auth::id();
								$an->updated_by = Auth::id();
								$an->save();
							}
						}

						if(!empty($request['questionaire_section'][$qsv_k]['sub_section'][$qssv_k]['question'])) {
							foreach ($request['questionaire_section'][$qsv_k]['sub_section'][$qssv_k]['question'] as $qssq_k => $quesv) {
								$ques = empty($quesv['question_id']) ? new Question : Question::find($quesv['question_id']);
								$ques->section_id = $qs->section_id;
								$ques->answer_type_id = $quesv['answer_type_id'];
								$ques->parent_question_id = $q->question_id;
								$ques->question_name = $quesv['question_name'];
								$ques->created_by = Auth::id();
								$ques->updated_by = Auth::id();
								$ques->save();

								if(!empty($request['questionaire_section'][$qsv_k]['sub_section'][$qssv_k]['question'][$qssq_k]['answer'])) {
									foreach ($request['questionaire_section'][$qsv_k]['sub_section'][$qssv_k]['question'][$qssq_k]['answer'] as $ansv) {
										$an = empty($ansv['answer_id']) ? new Answer : Answer::find($ansv['answer_id']);
										$an->question_id = $ques->question_id;
										$an->row_name = $ansv['row_name'];
										$an->answer_name = $ansv['answer_name'];
										$an->is_not_applicable = $ansv['is_not_applicable'];
										$an->score = $ansv['score'];
										$an->created_by = Auth::id();
										$an->updated_by = Auth::id();
										$an->save();
									}
								}
							}
						}
					}
				}
			}
		}

		return response()->json(['status' => 200]);
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
