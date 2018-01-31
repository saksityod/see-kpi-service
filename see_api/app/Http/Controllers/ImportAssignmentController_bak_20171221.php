<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use DB;
use Excel;
use Response;
use Exception;
use App\KPIType;
use App\Http\Requests;
use App\Http\Controllers\Controller;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class ImportAssignmentController extends Controller
{

  public function __construct(){
	   //$this->middleware('jwt.auth');
	}

  /**
   * Display item list.
   *
   * @author P.Wirun (GJ)
   * @param  \Illuminate\Http\Request  $request
   * @return \Illuminate\Http\Response
   */
   public function item_list(Request $request){
     $levelStr = "'".implode(",", $request->level_id)."'";
     $orgIdStr = "'".implode("','", $request->org_id)."'";

     $items = DB::select("
      SELECT distinct ai.structure_id, strc.structure_name, ai.item_id, ai.item_name, strc.form_id
      FROM appraisal_item ai
      INNER JOIN appraisal_structure strc ON strc.structure_id = ai.structure_id
      INNER JOIN appraisal_item_level vel ON vel.item_id = ai.item_id
      INNER JOIN appraisal_item_org iorg ON iorg.item_id = ai.item_id
      INNER JOIN appraisal_item_position post ON post.item_id = ai.item_id
      WHERE strc.is_active = 1
      AND vel.level_id IN({$levelStr})
      AND iorg.org_id IN({$orgIdStr})
      AND post.position_id = {$request->position_id}
      ORDER BY ai.structure_id, ai.item_id");

      $itemList = [];
      foreach($items as $value) {
        $itemList[$value->structure_name][] = [
          "structure_id" => $value->structure_id,
          "structure_name" => $value->structure_name,
          "item_id" => $value->item_id,
          "item_name" => $value->item_name,
          "form_id" => $value->form_id
        ];
      }
      $jsonResult["group"] = $itemList;

     return response()->json($itemList);
   }


   /**
    * Assignment export to Excel.
    *
    * @author P.Wirun (GJ)
    * @param  \Illuminate\Http\Request  $request
    * @return \Illuminate\Http\Response
    */
    public function export_template(Request $request){
       $items = DB::select("
        SELECT distinct ai.structure_id, strc.structure_name, ai.item_id, ai.item_name, strc.form_id
        FROM appraisal_item ai
        INNER JOIN appraisal_structure strc ON strc.structure_id = ai.structure_id
        INNER JOIN appraisal_item_level vel ON vel.item_id = ai.item_id
        INNER JOIN appraisal_item_org iorg ON iorg.item_id = ai.item_id
        INNER JOIN appraisal_item_position post ON post.item_id = ai.item_id
        WHERE strc.is_active = 1
        ORDER BY ai.structure_id, ai.item_id");

        // Grnerate sheet group array
        $sheetGroupArr = [];
        foreach ($items as $result) {
          if (!in_array($result->structure_name, $sheetGroupArr)) {
            array_push($sheetGroupArr, $result->structure_name);
          }
        }

        // Set data to array
        //$itemsArr = json_decode(json_encode($items), true);
        $itemList = [];
        foreach($items as $value) {
          $itemList[$value->structure_name][] = [
            "structure_id" => $value->structure_id,
            "structure_name" => $value->structure_name,
            "item_id" => $value->item_id,
            "item_name" => $value->item_name,
            "form_id" => $value->form_id
          ];
        }

        // return Excel::create('laravelcode', function($excel) use ($sheetGroupArr, $itemList) {
        //
        //   foreach ($sheetGroupArr as $group) {
        //
        //     $excel->sheet($group, function($sheet) use ($group, $itemList){
        //       $sheet->fromArray($itemList[$group]);
        //     });
        //
        //   }
        //
        // })->download("xlsx");

        return Excel::create('laravelcode', function($excel) use ($itemList) {

          foreach ($itemList as $key => $group) {

            $excel->sheet($key, function($sheet) use ($key, $itemList){
              $sheet->fromArray($itemList[$key]);
            });

          }

        })->download("xlsx");



    }

}
