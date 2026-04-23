<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use App\Models\Berita;

class BeritaController extends Controller
{
    /**
     * URL feed utama wp
     */
    private $feedUrl = 'https://smkn1bawang.sch.id/feed/';
    /**
     * Menampilkan daftar berita
     */
    public function index(Request $request)
    {
        $keyword = $request->keyword;

        $query = Berita::query();

        if ($keyword) {
            $query->where('judul', 'like', "%{$keyword}%")
                ->orWhere('deskripsi', 'like', "%{$keyword}%")
                ->orWhere('kategori', 'like', "%{$keyword}%");
        }

        $beritas = $query->orderByDesc('tanggal_publish')->paginate(10)->WithQueryString();
        return view('berita.index', compact('beritas', 'keyword'));
    }

    //sinkronisasi data dari feed

    public function sync()
    {
      try {
        $response = Http::timeout(20)->accept('application/rss+xml, application/xml, text/xml')->get($this->feedUrl);

        if ($response->failed()) {
            return redirect()->route('berita.index')->with('error', 'Gagal mengambil data feed dari website SMKN 1 Bawang.');
        }

        $xmlString = $response->body();

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xmlString, 'SimpleXMLElement', LIBXML_NOCDATA);

        if (!$xml) {
            return redirect()->route('berita.index')->with('error', 'Format XML feed tidak valid atau tidak dapat dibaca.');
        }

        $jumlahTersimpan = 0;

        if (isset($xml->channel->item)) {
            foreach ($xml->channel->item as $item) {
                $title = isset($item->title) ? trim((string) $item->title) : null;
                $link = isset($item->link) ? trim((string) $item->link) : null;
                $description = isset($item->description) ? trim(strip_tags((string) $item->description)) : null;
                $author = isset($item->children('dc', true)->creator) ? trim((string) $item->children('dc', true)->creator) : null;
                $kategori = null;
                if (isset($item->category)) {
                    $kategoriData = [];
                    foreach ($item->category as $cat) {
                        $kategoriData[] = trim((string) $cat);
                    }
                    $kategori = implode(', ', array_filter($kategoriData));
                }

                $tanggalpublish = null;
                if (isset($item->pubDate) && !empty((string) $item->pubDate)) {
                    $timestamp = strtotime((string) $item->pubDate);
                    if ($timestamp !== false) {
                        $tanggalpublish = date('Y-m-d H:i:s', $timestamp);
                    }
                }

                if (!$title || !$link) {
                    continue;
                }

                Berita::updateOrCreate(
                    ['link' => $link],
                    [
                        'judul' => $title,
                        'deskripsi' => $description,
                        'author' => $author,
                        'kategori' => $kategori,
                        'tanggal_publish' => $tanggalpublish,
                    ]
                );

            $jumlahTersimpan++;
            }
        }

        return redirect()->route('berita.index')->with('success', 'Sinkronisasi berhasil. Total data di proses: ' . $jumlahTersimpan . ' berita.');
        } catch (\Throwable $e) {
            return redirect()->route('berita.index')->with('error', 'Terjadi kesalahan saat sinkronisasi: ' . $e->getMessage());
        }
    }

    //hapus semua berita
    public function truncate()
    {
        Berita::truncate();
        return redirect()->route('berita.index')->with('success', 'Semua data berita berhasil dihapus.');
    }
}