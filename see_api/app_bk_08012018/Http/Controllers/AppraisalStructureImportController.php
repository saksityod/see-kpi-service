<?php
/**
 * Created by PhpStorm.
 * User: imake
 * Date: 12/19/17
 * Time: 7:11 PM
 */

namespace App\Http\Controllers;

class AppraisalStructureController extends Controller
{
    public function structure()
    {
        $appraisalStructures = \App\Model\AppraisalStructureModel::select('structure_id', 'structure_name')
            ->where('is_active', 1)
            ->orderBy('seq_no', 'asc')
            ->get();
        /*
        $appraisalStructures2 = DB::table('appraisal_structure')
            ->select('structure_id', 'structure_name')
            ->where('is_active', 1)
            ->orderBy('seq_no', 'asc')
            ->get();
        */
        return response()->json($appraisalStructures, 200);
    }
    public function template()
    {
        $templates = [
            [
                "master"   => "Master"
            ],
            [
                "detail"   => "Detail"
            ]
        ];

        return response()->json($templates, 200);
    }
}