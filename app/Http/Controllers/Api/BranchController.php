<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\BranchResource;
use App\Models\Branch;
use Illuminate\Http\Request;

class BranchController extends Controller
{
    public function me(Request $request): BranchResource
    {
        $branch = Branch::withoutGlobalScopes()->findOrFail($request->branch_id);

        return BranchResource::make($branch);
    }
}
