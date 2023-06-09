<?php

namespace App\Http\Controllers;

use App\Models\DTransaksiManyToMany;
use App\Models\Jurnal;
use App\Models\KodeAkun;
use App\Models\TransaksiManyToMany;
use Exception;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TransaksiManyToManyController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $data = TransaksiManyToMany::select('transaksi_many_to_many.*','kode_akun.kode_akun')
                                ->join('kode_akun','kode_akun.id','transaksi_many_to_many.kode_akun')
                                ->orderByDesc('created_at')
                                ->get();
        return view('pages.transaksi-back-office.transaksi-many.index',compact('data'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        $KodeAkun = KodeAkun::select('kode_akun.*',
                    'kode_induk.id as kode_induk_id',
                    'kode_induk.kode_induk as nama_kode')
                    ->join('kode_induk','kode_induk.id','kode_akun.id_induk')
                    ->where('kode_akun.nama_akun','NOT LIKE', "%tabungan mudharabah%")
                    ->get();
        return view('pages.transaksi-back-office.transaksi-many.create',compact('KodeAkun'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'tanggal' => 'required',
            'tipe' => 'required|not_in:0',
            'kode_akun' => 'required|not_in:0',
            // 'akun_lawan.*' => 'required',
            // 'nominal.*' => 'required',
            // 'ket.*' => 'required'
        ]);
        DB::beginTransaction();
        try {
            $transaksi = new TransaksiManyToMany;
            $transaksi->kode_transaksi = $this->generateKode();
            $transaksi->id_user = auth()->user()->id;
            $transaksi->tanggal = $request->get('tanggal');
            $transaksi->kode_akun = $request->get('kode_akun');
            $transaksi->tipe = $request->get('tipe');
            $transaksi->total = $request->get('total');
            $transaksi->keterangan = 'Transaksi Many To Many';
            $transaksi->save();

            foreach ($_POST['nominal'] as $key => $value) {
                // detail transaksi many to many
                $detailTransaksi = new DTransaksiManyToMany;
                $detailTransaksi->kode_transaksi = $transaksi->kode_transaksi;
                $detailTransaksi->kode_akun = $_POST['akun_lawan'][$key];
                $detailTransaksi->subtotal = $_POST['nominal'][$key];
                $detailTransaksi->keterangan = $_POST['ket'][$key];
                $detailTransaksi->save();

                // jurnal
                $jurnal = new Jurnal;
                $jurnal->tanggal = $request->get('tanggal');
                $jurnal->kode_transaksi = $transaksi->kode_transaksi;
                $jurnal->keterangan = $_POST['ket'][$key];
                $jurnal->kode_akun = $request->get('kode_akun');
                $jurnal->kode_lawan = $_POST['akun_lawan'][$key];
                $jurnal->tipe = $request->tipe == 'Masuk' ? 'debit' : 'kredit';
                $jurnal->nominal =  $_POST['nominal'][$key];
                $jurnal->id_detail = $detailTransaksi->id;
                $jurnal->save();
            }
            DB::commit();
            return redirect()->route('transaksi-many-to-many.index')->withStatus('Berhasil menambahkan data transaksi');

        } catch (Exception $e) {
            DB::rollBack();
            return redirect()->route('transaksi-many-to-many.index')->withError('Terjadi Kesalahan');
        } catch (QueryException $e) {
            DB::rollBack();
            return redirect()->back()->withError('Terjadi kesalahan.');
       }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $data = TransaksiManyToMany::select('transaksi_many_to_many.*','kode_akun.kode_akun')
                                    ->join('kode_akun','kode_akun.id','transaksi_many_to_many.kode_akun')
                                    ->where('transaksi_many_to_many.id',$id)
                                    ->first();
        $detail = DTransaksiManyToMany::select('detail_transaksi_many_to_many.*','kode_akun.kode_akun')
                                    ->join('kode_akun','kode_akun.id','detail_transaksi_many_to_many.kode_akun')
                                    ->where('detail_transaksi_many_to_many.kode_transaksi',$data->kode_transaksi)
                                    ->get();
        return view('pages.transaksi-back-office.transaksi-many.show',compact('data','detail'));
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $transaksi = TransaksiManyToMany::find($id);
        DTransaksiManyToMany::where('kode_transaksi',$transaksi->kode_transaksi)->first()->delete();
        Jurnal::where('kode_transaksi',$transaksi->kode_transaksi)->first()->delete();
        $transaksi->delete();
        return redirect()->route('transaksi-many-to-many.index')->withStatus('Berhasil menghapus data.');
    }

    function kodeAkun() {
        $data = KodeAkun::select('kode_akun.*',
                    'kode_induk.id as kode_induk_id',
                    'kode_induk.kode_induk as nama_kode')
                    ->join('kode_induk','kode_induk.id','kode_akun.id_induk')
                    ->where('kode_akun.nama_akun','NOT LIKE', "%tabungan mudharabah%")
                    ->get();
        return response()->json($data);
    }

    function generateKode() {
        $nosaldo = null;
        $transaksi = TransaksiManyToMany::orderBy('created_at', 'DESC')->get();
        $date = date('Ymd');
        if($transaksi->count() > 0) {
            $notransaksi = $transaksi[0]->kode_transaksi;

            $lastIncrement = substr($notransaksi, 10);
            $notransaksi = str_pad($lastIncrement + 1, 3, 0, STR_PAD_LEFT);
            return $notransaksi = 'TM'.$date.$notransaksi;
        }
        else {
            return $notransaksi = 'TM'.$date."001";

        }
    }
}
