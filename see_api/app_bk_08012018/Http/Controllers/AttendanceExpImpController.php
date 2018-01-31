<?php
/**
 * Created by PhpStorm.
 * User: imake
 * Date: 12/19/17
 * Time: 9:38 PM
 */

namespace App\Http\Controllers;
use App\Http\Services\AppraisalService;
use App\Http\Services\AppraialValidator;
use App\Model\AppraisalStructureModel;
use Excel;
use Illuminate\Http\Request;
use Log;

class AttendanceExpImpController extends Controller
{
    public function exportMaster(Request $request){
        $filename = "master_import_attendance";
        $structure_id = $request->structure_id;
        Excel::create($filename, function($excel)  use ($structure_id) {
            $appraisalStructure = AppraisalStructureModel::find($structure_id);
            $excel->sheet($appraisalStructure->structure_name, function($sheet) {
                $headers = ["ตัวชี้วัด"];
                $sheet->appendRow($headers);
            });
        })->export('xlsx');
    }

    public function importMaster(Request $request){
        set_time_limit(0);
        $bank_values = [];
        $number_values = ['E','F','G','H','I','J'];
        $all_number_values = [];
        $appraialValidator = new AppraialValidator();
        $result_obj = $appraialValidator->validateTemplate(1,$request,$bank_values,$number_values,$all_number_values);

        if($result_obj->result_status == 1 )
            AppraisalService::importMaster($request);

        return response()->json($result_obj, 200);
    }

    public function exportDetail(Request $request){
        $filename = "detail_import_attendance";
        $structure_id = $request->structure_id;
        Excel::create($filename, function($excel) use ($structure_id) {
            $appraisalStructure = AppraisalStructureModel::find($structure_id);
            AppraisalService::createDetailSheet($structure_id,$appraisalStructure->structure_name,$excel);
            AppraisalService::createDetailMasterSheet($excel);
        })->export('xlsx');
    }

    public function importDetail(Request $request){
        set_time_limit(0);
        $bank_values = [];
        $number_values = ['A'];
        $all_number_values = ['D'];
        $appraialValidator = new AppraialValidator();
        $result_obj = $appraialValidator->validateTemplate(3,$request,$bank_values,$number_values,$all_number_values);

        if($result_obj->result_status == 1 )
            AppraisalService::importDetail($request);

        return response()->json($result_obj, 200);
    }
}