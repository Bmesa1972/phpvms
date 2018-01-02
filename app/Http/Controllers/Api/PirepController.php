<?php

namespace App\Http\Controllers\Api;

use Log;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

use App\Models\Acars;
use App\Models\Pirep;

use App\Models\Enums\AcarsType;
use App\Models\Enums\PirepState;
use App\Models\Enums\PirepStatus;

use App\Services\GeoService;
use App\Services\PIREPService;
use App\Repositories\AcarsRepository;
use App\Repositories\PirepRepository;

use App\Http\Resources\Acars as AcarsResource;
use App\Http\Resources\Pirep as PirepResource;

use App\Http\Controllers\AppBaseController;

class PirepController extends AppBaseController
{
    protected $acarsRepo,
              $geoSvc,
              $pirepRepo,
              $pirepSvc;

    protected $check_attrs = [
        'airline_id',
        'aircraft_id',
        'dpt_airport_id',
        'arr_airport_id',
        'flight_id',
        'flight_number',
        'route_code',
        'route_leg',
        'flight_time',
        'planned_flight_time',
        'level',
        'route',
        'notes',
    ];

    public function __construct(
        AcarsRepository $acarsRepo,
        GeoService $geoSvc,
        PirepRepository $pirepRepo,
        PIREPService $pirepSvc
    ) {
        $this->acarsRepo = $acarsRepo;
        $this->geoSvc = $geoSvc;
        $this->pirepRepo = $pirepRepo;
        $this->pirepSvc = $pirepSvc;
    }

    public function get($id)
    {
        PirepResource::withoutWrapping();
        return new PirepResource($this->pirepRepo->find($id));
    }

    /**
     * Create a new PIREP and place it in a "inprogress" and "prefile" state
     * Once ACARS updates are being processed, then it can go into an 'ENROUTE'
     * status, and whatever other statuses may be defined
     *
     * TODO: Allow extra fields, etc to be set. Aircraft, etc
     */
    public function prefile(Request $request)
    {
        Log::info('PIREP Prefile, user '. Auth::user()->pilot_id, $request->toArray());

        $attrs = [
            'user_id'   => Auth::user()->id,
            'state'     => PirepState::IN_PROGRESS,
            'status'    => PirepStatus::PREFILE,
        ];

        foreach ($this->check_attrs as $attr) {
            if ($request->filled($attr)) {
                $attrs[$attr] = $request->get($attr);
            }
        }

        $pirep = new Pirep($attrs);

        # Find if there's a duplicate, if so, let's work on that
        $dupe_pirep = $this->pirepSvc->findDuplicate($pirep);
        if($dupe_pirep !== false) {
            $pirep = $dupe_pirep;
        }

        $pirep->save();

        $this->pirepSvc->saveRoute($pirep);

        Log::info('PIREP PREFILED');
        Log::info($pirep->id);

        PirepResource::withoutWrapping();
        return new PirepResource($pirep);
    }

    /**
     * File the PIREP
     * @param $id
     * @param Request $request
     * @return PirepResource
     */
    public function file($id, Request $request)
    {
        Log::info('PIREP Prefile, user ' . Auth::user()->pilot_id, $request->toArray());

        $attrs = [
            'state'     => PirepState::PENDING,
            'status'    => PirepStatus::ARRIVED,
        ];

        foreach($this->check_attrs as $attr) {
            if($request->filled($attr)) {
                $attrs[$attr] = $request->get($attr);
            }
        }

        if($request->filled('fields')) {
            $pirep_fields = $request->get('fields');
        }

        try {
            $pirep = $this->pirepRepo->update($attrs, $id);
            $pirep = $this->pirepSvc->create($pirep, $pirep_fields);
        } catch (\Exception $e) {
            Log::error($e);
        }

        PirepResource::withoutWrapping();
        return new PirepResource($pirep);
    }

    /**
     * Return the GeoJSON for the ACARS line
     * @param $id
     * @param Request $request
     * @return \Illuminate\Contracts\Routing\ResponseFactory
     */
    public function acars_get($id, Request $request)
    {
        $pirep = $this->pirepRepo->find($id);
        $geodata = $this->geoSvc->getFeatureFromAcars($pirep);

        return response(\json_encode($geodata), 200, [
            'Content-Type' => 'application/json',
        ]);
    }

    /**
     * Post ACARS updates for a PIREP
     * @param $id
     * @param Request $request
     * @return AcarsResource
     */
    public function acars_store($id, Request $request)
    {
        $pirep = $this->pirepRepo->find($id);

        Log::info('Posting ACARS update', $request->toArray());
        $attrs = $request->toArray();

        $attrs['pirep_id'] = $id;
        $attrs['type'] = AcarsType::FLIGHT_PATH;

        $update = Acars::create($attrs);
        $update->save();

        # Change the PIREP status
        $pirep->status = PirepStatus::ENROUTE;
        $pirep->save();

        AcarsResource::withoutWrapping();
        return new AcarsResource($update);
    }
}
