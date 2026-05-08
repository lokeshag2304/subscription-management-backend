<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Http;
use App\Lib\SMSinteg;
use App\Lib\Whatsappinteg;
use App\Lib\EmailInteg;
use Carbon\Carbon;
use App\Services\CryptService;
use App\Services\CustomCipherService;



class RemarkCategoriesController extends Controller
{

public function addCategoryRemark(Request $request)
{
    try {

      
        $validator = Validator::make($request->all(), [
            's_id'   => 'required|integer',
            'cat_id' => 'required|integer',
            'remark' => 'required|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->errors()->first()
            ], 422);
        }

 
        $remarkId = DB::table('category_remarks')->insertGetId([
            's_id'       => $request->s_id,
            'cat_id'     => $request->cat_id,
            'remark'     => $request->remark,
            'created_at' => now()
        ]);

       
        return response()->json([
            'status'  => true,
            'message' => 'Remark added successfully',
            'id'      => $remarkId
        ]);

    } catch (\Exception $e) {

        \Log::error('addCategoryRemark error', [
            'error' => $e->getMessage()
        ]);

        return response()->json([
            'status' => false,
            'message' => 'Something went wrong',
            'error' => $e->getMessage()
        ], 500);
    }
}




}
