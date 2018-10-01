<?php

namespace App\Http\Controllers\PMTL;

use App\QuestionaireAuthorize;
use App\QuestionaireType;

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

class QuestionaireTypeController extends Controller
{
	public function __construct()
	{
	   $this->middleware('jwt.auth');
	}

	public function index() {
		$items = DB::select("
			SELECT questionaire_type_id, questionaire_type
			FROM questionaire_type
			ORDER BY questionaire_type_id
			");
		return response()->json($items);
	}

	public function show($id) {
		try {
			$item = QuestionaireType::findOrFail($id);
		} catch (ModelNotFoundException $e) {
			return response()->json(['status' => 404, 'data' => 'QuestionaireType Not Found.']); 
		}
		return response()->json($item);
	}

	public function store(Request $request) {
		$errors = [];
		$validator = Validator::make([
			'questionaire_type' => $request->questionaire_type
		], [
			'questionaire_type' => 'required|max:255|unique:questionaire_type,questionaire_type'
		]);

		if($validator->fails()) {
			return response()->json(['status' => 404, 'errors' => $validator->errors()]);
		}

		$qt = new QuestionaireType;
		$qt->questionaire_type = $request->questionaire_type;
		$qt->created_by = Auth::id();
		$qt->updated_by = Auth::id();
		try {
			$qt->save();
		} catch (Exception $e) {
			$errors[] = ['QuestionaireType' => substr($e, 0, 255)];
		}

		return response()->json(['status' => 200, 'errors' => $errors]);
	}

	public function update($id, Request $request) {
		try {
			$qt = QuestionaireType::findOrFail($id);
		} catch (ModelNotFoundException $e) {
			return response()->json(['status' => 404, 'data' => 'QuestionaireType Not Found.']); 
		}

		$errors = [];
		$validator = Validator::make([
			'questionaire_type' => $request->questionaire_type
		], [
			'questionaire_type' => 'required|max:255|unique:questionaire_type,questionaire_type,'.$id.',questionaire_type_id'
		]);

		if($validator->fails()) {
			return response()->json(['status' => 404, 'errors' => $validator->errors()]);
		}

		$qt->questionaire_type = $request->questionaire_type;
		$qt->updated_by = Auth::id();
		try {
			$qt->save();
		} catch (Exception $e) {
			$errors[] = ['QuestionaireType' => substr($e, 0, 255)];
		}

		return response()->json(['status' => 200, 'errors' => $errors]);
	}

	public function manage($id) {
		try {
			$item = QuestionaireType::findOrFail($id);
		} catch (ModelNotFoundException $e) {
			return response()->json(['status' => 404, 'data' => 'QuestionaireType Not Found.']); 
		}

		$data = DB::select("
			SELECT *
			FROM job_function 
			WHERE is_evaluated = 1
			ORDER BY job_function_id
			");

		foreach ($data as $key => $value) {
			$data[$key]->questionaire = DB::select("
				SELECT q.questionaire_id, 
						q.questionaire_name, 
						if(qa.questionaire_id is null, 0, 1) is_check
				FROM questionaire_authorize qa
				RIGHT JOIN questionaire q ON q.questionaire_id = qa.questionaire_id 
				AND qa.job_function_id = '{$value->job_function_id}'
				WHERE q.questionaire_type_id = '{$id}'
				AND q.is_active = 1
			");
		}

		return response()->json(['head' => $item, 'data' => $data]);
	}

	public function manage_update($id, Request $request) {
		try {
			$item = QuestionaireType::findOrFail($id);
		} catch (ModelNotFoundException $e) {
			return response()->json(['status' => 404, 'data' => 'QuestionaireType Not Found.']); 
		}

		$errors = [];
		$errors_validator = [];
		foreach ($request->all() as $key => $value) {
			$validator = Validator::make([
				'job_function_id' => $value['job_function_id'],
				'questionaire_id' => $value['questionaire_id']
			], [
				'job_function_id' => 'required|integer',
				'questionaire_id' => 'required|integer',
			]);

			if($validator->fails()) {
				$errors_validator[] = $validator->errors();
			}
		}

		if(!empty($errors_validator)) {
			return response()->json(['status' => 400, 'errors' => $errors_validator]);
		}

		QuestionaireAuthorize::where("questionaire_type_id", $id)->delete();

		foreach ($request->all() as $key => $value) {
			$qt = new QuestionaireAuthorize;
			$qt->questionaire_type_id = $id;
			$qt->job_function_id = $value['job_function_id'];
			$qt->questionaire_id = $value['questionaire_id'];
			try {
				$qt->save();
			} catch (Exception $e) {
				$errors[] = ['QuestionaireType' => substr($e, 0, 255)];
			}
		}

		return response()->json(['status' => 200, 'errors' => $errors]);
	}

	public function destroy($id) {
		try {
			$item = QuestionaireType::findOrFail($id);
		} catch (ModelNotFoundException $e) {
			return response()->json(['status' => 404, 'data' => 'QuestionaireType Not Found.']); 
		}

		try {
			$item->delete();
		} catch (Exception $e) {
			if ($e->errorInfo[1] == 1451) {
				return response()->json(['status' => 400, 'data' => 'Cannot delete because this QuestionaireType is in use.']);
			} else {
				return response()->json($e->errorInfo);
			}
		}

		return response()->json(['status' => 200]);
	}

}