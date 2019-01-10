<?php

namespace App\Http\Controllers;

use App\AppraisalForm;

use Auth;
use DB;
use Validator;
use Exception;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class AppraisalFormController extends Controller
{

	public function __construct()
	{
	   $this->middleware('jwt.auth');
	}


	public function index(Request $request)
	{
		$items = AppraisalForm::orderBy('appraisal_form_id')->get();
		return response()->json($items);
	}


	public function store(Request $request)
	{

		$validator = Validator::make($request->all(), [
			'appraisal_form_name' => 'required|max:100|unique:appraisal_form,appraisal_form_name',
			'is_bonus' => 'required|boolean',
			'is_active' => 'required|boolean',
			'is_raise' => 'required|boolean',
			'is_mpi' => 'required|boolean',
		]);

		if ($validator->fails()) {
			return response()->json(['status' => 400, 'data' => $validator->errors()]);
		} else {
			$item = new AppraisalForm;
			$item->fill($request->all());
			$item->created_by = Auth::id();
			$item->update_by = Auth::id();
			$item->save();
		}

		return response()->json(['status' => 200, 'data' => $item]);
	}


	public function show($form_id)
	{
		try {
			$item = AppraisalForm::findOrFail($form_id);
		} catch (ModelNotFoundException $e) {
			return response()->json(['status' => 404, 'data' => 'Appraisal Form not found.']);
		}
		return response()->json($item);
	}


	public function update(Request $request, $form_id)
	{
		try {
			$item = AppraisalForm::findOrFail($form_id);
		} catch (ModelNotFoundException $e) {
			return response()->json(['status' => 404, 'data' => 'Appraisal Form not found.']);
		}

		$validator = Validator::make($request->all(), [
			'appraisal_form_name' => 'required|max:100|unique:appraisal_form,appraisal_form_name,' . $form_id . ',appraisal_form_id',
			'is_bonus' => 'required|boolean',
			'is_active' => 'required|boolean',
			'is_raise' => 'required|boolean',
			'is_mpi' => 'required|boolean',
		]);

		if ($validator->fails()) {
			return response()->json(['status' => 400, 'data' => $validator->errors()]);
		} else {
			$item->fill($request->all());
			$item->update_by = Auth::id();
			$item->save();
		}

		return response()->json(['status' => 200, 'data' => $item]);

	}

	public function destroy($form_id)
	{
		try {
			$item = AppraisalForm::findOrFail($form_id);
		} catch (ModelNotFoundException $e) {
			return response()->json(['status' => 404, 'data' => 'Appraisal Form not found.']);
		}

		try {
			$item->delete();
		} catch (Exception $e) {
			if ($e->errorInfo[1] == 1451) {
				return response()->json(['status' => 400, 'data' => 'Cannot delete because this Appraisal Form is in use.']);
			} else {
				return response()->json($e->errorInfo);
			}
		}

		return response()->json(['status' => 200]);

	}
}
