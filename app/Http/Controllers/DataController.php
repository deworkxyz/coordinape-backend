<?php

namespace App\Http\Controllers;

use App\Http\Requests\NewGiftRequest;
use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use App\Models\PendingTokenGift;
use DB;
use App\Models\TokenGift;
use App\Repositories\EpochRepository;
use App\Http\Requests\CsvRequest;
use App\Http\Requests\TeammatesRequest;
use App\Helper\Utils;
use App\Models\Epoch;
use App\Models\Protocol;
use App\Models\Burn;
use Illuminate\Support\Facades\Log;


class DataController extends Controller
{
    protected $repo ;

    public function __construct(EpochRepository $repo)
    {
        $this->repo = $repo;
    }

    public function getProtocols(Request $request): JsonResponse
    {
        return response()->json(Protocol::all());
    }

    public function newUpdateGifts(NewGiftRequest $request, $circle_id, $address): JsonResponse
    {
        $user = $request->user;
        if ($user->non_giver) {
            return response()->json(['message'=>'User does not have permission to give'], 403);
        };

        $this->repo->newUpdateGifts($request, $address, $circle_id);
        $user->load(['teammates','pendingSentGifts']);
        return response()->json($user);
    }

    public function getPendingGifts(Request $request, $circle_id = null): JsonResponse {
        $filters = $request->all();

        if($circle_id) {
            $filters['circle_id'] = $circle_id;
        }
        else if(empty($filters['circle_id']))
        {
            return response()->json([]);
        }

        if(!empty($filters['recipient_address'])) {
            $user = User::byAddress($request->recipient_address)->where('circle_id',$circle_id)->first();
            $filters['recipient_id'] = $user->id;
        }

        if(!empty($filters['sender_address'])) {
            $user = User::byAddress($request->sender_address)->where('circle_id',$circle_id)->first();
            $filters['sender_id'] = $user->id;
        }

        return response()->json(PendingTokenGift::filter($filters)->get());
    }

    public function getGifts(Request $request, $circle_id = null): JsonResponse {
        $filters = $request->all();
        if($circle_id) {
            $filters['circle_id'] = $circle_id;
        }

        if(!empty($filters['recipient_address'])) {
            $user = User::byAddress($request->recipient_address)->where('circle_id',$circle_id)->first();
            $filters['recipient_id'] = $user->id;
        }

        if(!empty($filters['sender_address'])) {
            $user = User::byAddress($request->sender_address)->where('circle_id',$circle_id)->first();
            $filters['sender_id'] = $user->id;
        }

        return response()->json( Utils::queryCache($request,function () use($filters,$request) {
            return TokenGift::filter($filters)->limit(20000)->get();
        }, 60, $circle_id));
    }

    public function updateTeammates(TeammatesRequest $request, $circle_id) : JsonResponse {

        $user = $request->user;
        $teammates = $request->teammates;
        $circle_teammates = User::where('circle_id', $circle_id)->where('is_hidden',0)->where('id','<>',$user->id)->whereIn('id',$teammates)->pluck('id');
        DB::transaction(function () use ($circle_teammates, $user) {
            if ($circle_teammates) {
                $user->teammates()->sync($circle_teammates);
            }
        });
        $user->load(['teammates','pendingSentGifts']);
        return response()->json($user);
    }

    public function generateCsv(CsvRequest $request, $circle_id = null)
    {
        if (!$circle_id) {
            if (!$request->circle_id)
                return response()->json(['message'=> 'Circle not Found'], 422);
            $circle_id = $request->circle_id;
        }

        $epoch = null;
        if($request->epoch_id) {
            $epoch = Epoch::with('circle.protocol')->where('circle_id',$circle_id)->where('id',$request->epoch_id )->first();

        } else if ($request->epoch) {
            $epoch = Epoch::with('circle.protocol')->where('circle_id',$circle_id)->where('number', $request->epoch)->first();
        }
        if(!$epoch)
            return 'Epoch Not found';

        return $this->repo->getEpochCsv($epoch, $circle_id, $request->grant);
    }

}
