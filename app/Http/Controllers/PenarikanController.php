<?php

namespace App\Http\Controllers;

use App\Models\BukuTabungan;
use App\Models\Denominasi;
use App\Models\DTransaksiManyToMany;
use App\Models\Jurnal;
use App\Models\PembukaanRekening;
use App\Models\Penarikan;
use App\Models\SaldoTeller;
use App\Models\Setoran;
use App\Models\TransaksiManyToMany;
use App\Models\TransaksiTabungan;
use App\Models\User;
use Carbon\Carbon;
use Exception;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PenarikanController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $data = PembukaanRekening::select(
                'rekening_tabungan.id',
                'rekening_tabungan.nasabah_id',
                'rekening_tabungan.no_rekening',
                'rekening_tabungan.saldo_awal',
                'nasabah.id as id_nasabah',
                'nasabah.no_anggota',
                'nasabah.nama'
            )->join('nasabah','nasabah.id','rekening_tabungan.nasabah_id')->where('nasabah.status','aktif')->get();


        /* generate no penarikan  */
        $noPenarikan = null;
        $penarikan = TransaksiTabungan::orderBy('created_at', 'DESC')->where('jenis','keluar')->get();

        if($penarikan->count() > 0) {
            $noPenarikan = $penarikan[0]->kode;

            $lastIncrement = substr($noPenarikan, 7);
            $noPenarikan = str_pad($lastIncrement + 1, 5, 0, STR_PAD_LEFT);
            $noPenarikan = 'TRK'.$noPenarikan;
        }
        else {
            $noPenarikan = 'TRK'."00001";

        }

        $penarikan = TransaksiTabungan::select('transaksi_tabungan.*',
                                'rekening_tabungan.nasabah_id',
                                'rekening_tabungan.no_rekening',
                                'nasabah.id as id_nasabah',
                                'nasabah.nama',
                                'nasabah.nik',
                                'users.id as id_user',
                                'users.kode_user'
                                )->join(
                                    'rekening_tabungan','rekening_tabungan.nasabah_id','transaksi_tabungan.id_nasabah'
                                )->join(
                                    'nasabah','nasabah.id','rekening_tabungan.nasabah_id'
                                )
                                ->join(
                                    'users', 'users.id', 'transaksi_tabungan.id_user'
                                )
                                ->where('transaksi_tabungan.jenis','keluar')
                                ->orderByDesc('transaksi_tabungan.created_at')
                                ->get();
        return view('pages.penarikan.index',compact('data','noPenarikan','penarikan'));
    }

    public function cekTabungan(Request $request)
    {
        try{
            $data = BukuTabungan::where('id_rekening_tabungan',$request->get('id'))->first()->saldo;
            return $data;

        } catch (Exception $e) {
            return response()->json([
                'data' => null,
                'message' => $e->getMessage()
            ]);
        } catch (QueryException $e){
            return response()->json([
                'data' => null,
                'message' => $e->getMessage()
            ]);
        }
        return $request;
    }
    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'tgl' => 'required',
            'nominal_penarikan' => 'required',
        ]);
        if ($this->formatNumber($request->get('nominal_penarikan')) < 20000) {
            return redirect()->route('penarikan.index')->withError('Maaf batas penarikan sebesar Rp. 20.000');
        }
        $tabungan = BukuTabungan::where('id_rekening_tabungan',$request->get('id_nasabah'));
        $saldo_akhir = $tabungan->first()->saldo;
        if ($this->formatNumber($request->get('nominal_penarikan')) > (int)$saldo_akhir) {
            return redirect()->route('penarikan.index')->withError('Maaf saldo anda tidak mencukupi penarikan');
        }
        $currentDate = Carbon::now()->toDateString();
        $query = Denominasi::
                        where('status_akun','non-general')
                        ->whereDate('created_at','=',$currentDate);
        $nominal_denominasi = $query->where('id_user',auth()->user()->id)->sum('total');
        $pembayaran = SaldoTeller::where('status','pembayaran')
                        ->where('id_user',auth()->user()->id)
                        ->where('tanggal',$currentDate)
                        // ->sum('pembayaran');
                        ->first();
        $result = isset($pembayaran) ? (int) $pembayaran->penerimaan : 0;
        $nominal = $result - (int) $nominal_denominasi;
        if ($nominal <= 0) {
            return redirect()->route('penarikan.index')->withError('Maaf tidak bisa melakukan penarikan saldo teller tidak mencukupi ');
        }
        $pembayaran = SaldoTeller::where('status','pembayaran')
            ->where('id_user',auth()->user()->id)
            ->where('tanggal',$currentDate)
            // ->sum('pembayaran');
            ->first();
        if ($pembayaran == NULL) {
            return redirect()->route('penarikan.index')->withError('Maaf tidak bisa melakukan penarikan saldo teller tidak mencukupi ');
        }
        try {
            $penarikan = new TransaksiTabungan;
            $penarikan->kode = $request->get('kode_penarikan');
            $penarikan->id_nasabah = $request->get('id_nasabah');
            $penarikan->tgl = $request->get('tgl');
            $penarikan->nominal = $this->formatNumber($request->get('nominal_penarikan'));
            $penarikan->ket = $request->get('ket');
            $penarikan->jenis = 'keluar';
            $penarikan->id_user = auth()->user()->id;
            if ($this->formatNumber($request->get('nominal_penarikan')) > 1000000) {
                $penarikan->status = 'pending';
            }else{
                $tabungan = BukuTabungan::where('id_rekening_tabungan',$request->get('id_nasabah'));
                $saldo_akhir = $tabungan->first()->saldo;
                $result_saldo =  $saldo_akhir - $penarikan->nominal;
                if ($result_saldo < 20000 ) {
                    return redirect()->route('penarikan.index')->withError('Maaf saldo anda tidak mencukupi penarikan');
                }
                $tabungan->update([
                    'saldo' => $result_saldo,
                ]);
                $penarikan->saldo = $result_saldo;
                // update penerimaan
                $nominal_denominasi = $query->where('id_user',auth()->user()->id)->sum('total');
                if ($nominal_denominasi > 0) {
                    $currentDate = Carbon::now()->toDateString();
                    $pembayaran = SaldoTeller::where('status','pembayaran')
                        ->where('id_user',auth()->user()->id)
                        ->where('tanggal',$currentDate)
                        // ->sum('pembayaran');
                        ->first();
                    $penerimaan = $pembayaran->penerimaan - $penarikan->nominal;
                    $pembayaran->penerimaan = $penerimaan;
                    $pembayaran->update();
                } else {
                    $currentDate = Carbon::now()->toDateString();
                    $pembayaran = SaldoTeller::where('status','pembayaran')
                        ->where('id_user',auth()->user()->id)
                        ->where('tanggal',$currentDate)
                        // ->sum('pembayaran');
                        ->first();
                    $penerimaan = $pembayaran->penerimaan - $penarikan->nominal;
                    $pembayaran->penerimaan = $penerimaan;
                    $pembayaran->update();
                }

                $penarikan->status = 'setuju';
            }
            $kode_akun = BukuTabungan::where('id_rekening_tabungan',$request->get('id_nasabah'))->first()->id_kode_akun;

            $transaksi = new TransaksiManyToMany();
            $transaksi->kode_transaksi = $this->generateKode();
            $transaksi->id_user = auth()->user()->id;
            $transaksi->tanggal = $request->get('tgl');
            $transaksi->kode_akun = $kode_akun;
            $transaksi->tipe = 'debit';
            $transaksi->total = $this->formatNumber($request->get('nominal_penarikan'));
            $transaksi->keterangan = 'Transaksi Many To Many';
            $transaksi->save();

            $detailTransaksi = new DTransaksiManyToMany();
            $detailTransaksi->kode_transaksi = $transaksi->kode_transaksi;
            $detailTransaksi->kode_akun = $kode_akun;
            $detailTransaksi->subtotal = $this->formatNumber($request->get('nominal_penarikan'));
            $detailTransaksi->keterangan = 'tabungan';
            $detailTransaksi->save();

            $jurnal = new Jurnal;
            $jurnal->tanggal = $request->get('tgl');
            $jurnal->kode_transaksi = $transaksi->kode_transaksi;
            $jurnal->keterangan = 'tabungan';
            $jurnal->kode_akun = $kode_akun;
            $jurnal->kode_lawan = 0;
            $jurnal->tipe = 'debit';
            $jurnal->nominal =  $this->formatNumber($request->get('nominal_penarikan'));
            $jurnal->id_detail = $detailTransaksi->id;
            $jurnal->save();

            $penarikan->save();

            return redirect()->route('penarikan.index')->withStatus('Berhasil melakukan penarikan.');

        } catch (Exception $e) {
            return $e;
            return redirect()->route('penarikan.index')->withError('Terjadi kesalahan.');
        } catch (QueryException $e) {
            return $e;
            return redirect()->route('penarikan.index')->withError('Terjadi kesalahan.');
        }
    }

    public function formatNumber($param)
    {
        return (int)str_replace('.', '', $param);
    }
    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        $data = TransaksiTabungan::select('transaksi_tabungan.*',
                                        'rekening_tabungan.id as rekening_tabungan_id',
                                        'rekening_tabungan.nasabah_id',
                                        'rekening_tabungan.no_rekening',
                                        'nasabah.id as id_nasabah',
                                        'nasabah.nama',
                                        'buku_tabungan.id as id_tabungan',
                                        'buku_tabungan.saldo'
                                        )->join(
                                            'rekening_tabungan','rekening_tabungan.id','transaksi_tabungan.id_nasabah'
                                        )
                                        ->join(
                                            'buku_tabungan','buku_tabungan.id_rekening_tabungan','rekening_tabungan.id'
                                        )
                                        ->join(
                                            'nasabah','nasabah.id','rekening_tabungan.nasabah_id'
                                        )
                                        ->where('transaksi_tabungan.id',$id)
                                        ->first();
        return view('pages.penarikan.edit',compact('data'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $request->validate([
            'tgl' => 'required',
            'nominal_penarikan' => 'required',
        ]);

        $penarikan = TransaksiTabungan::find($id);
        $tabungan = BukuTabungan::where('id_rekening_tabungan',$penarikan->id_nasabah);
        $saldo_akhir = $tabungan->first()->saldo + $penarikan->nominal;
        if ($this->formatNumber($request->get('nominal_penarikan')) > (int)$saldo_akhir) {
            return redirect()->route('penarikan.index')->withError('Maaf saldo anda tidak mencukupi penarikan');
        }
        try {
            $penarikan = TransaksiTabungan::find($id);
            $penarikan->tgl = $request->get('tgl');
            $penarikan->nominal = $this->formatNumber($request->get('nominal_penarikan'));
            $penarikan->ket = $request->get('ket');
            $penarikan->jenis = 'keluar';
            $penarikan->id_user = auth()->user()->id;
            if ($this->formatNumber($request->get('nominal_penarikan')) > 1000000) {
                $penarikan->status = 'pending';
            }else{
                $tabungan = BukuTabungan::where('id_rekening_tabungan', $penarikan->id_nasabah);

                // update penerimaan
                $currentDate = Carbon::now()->toDateString();
                $pembayaran = SaldoTeller::where('status','pembayaran')
                    ->where('id_user',auth()->user()->id)
                    ->where('tanggal',$currentDate)
                    // ->sum('pembayaran');
                    ->first();
                $penerimaan = $pembayaran->pembayaran - $this->formatNumber($request->get('nominal_penarikan'));
                $pembayaran->penerimaan = $penerimaan;
                $tabungan->update([
                    'saldo' => $this->formatNumber($request->get('sisa_saldo')),
                ]);
                $penarikan->status = 'setuju';
            }
            $penarikan->update();

            return redirect()->route('penarikan.index')->withStatus('Berhasil melakukan perubahan penarikan.');

        } catch (Exception $e) {
            return redirect()->route('penarikan.index')->withError('Terjadi kesalahan.');
        } catch (QueryException $e) {
            return redirect()->route('penarikan.index')->withError('Terjadi kesalahan.');
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        try{
            $penarikan = TransaksiTabungan::find($id);
            $tabungan = BukuTabungan::where('id_rekening_tabungan', $penarikan->id_nasabah);
            $saldo_akhir = $tabungan->first()->saldo;
            $result_saldo =  $saldo_akhir + $penarikan->nominal;
            $tabungan->update([
                'saldo' => $result_saldo,
            ]);
            $penarikan->delete();
            return redirect()->route('penarikan.index')->withStatus('Berhasil menghapus data.');
        } catch (Exception $e) {
            return redirect()->route('penarikan.index')->withError('Terjadi kesalahan.');
        } catch (QueryException $e){
            return redirect()->route('penarikan.index')->withError('Terjadi kesalahan.');
        }
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
