<?php

namespace Database\Seeders;

use App\Models\DataSource;
use App\Models\Instrument;
use App\Models\NewsArticle;
use App\Models\ResearchNote;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

class DemoNewsAndResearchSeeder extends Seeder
{
    public function run(): void
    {
        if (! config('nadi.market_data.demo.enabled', true)) {
            return;
        }

        $source = DataSource::query()->where('code', 'demo')->first();
        if (! $source || ! $source->is_enabled) {
            return;
        }

        $instruments = Instrument::query()->get()->keyBy('symbol');
        $now = CarbonImmutable::now('UTC');

        $articles = [
            [
                'slug' => 'demo-idx-bbca-dividend-and-casa-growth',
                'title' => 'BBCA Catat Pertumbuhan Dana Murah (CASA) dan Proyeksi Dividen Final Kompetitif (Demo)',
                'summary' => 'Simulasi ringkasan kinerja perbankan utama IDX dengan pertumbuhan kredit sehat dan rasio CASA di atas rata-rata industri.',
                'body' => "Ringkasan Editorial Demo: Bank Central Asia Tbk (BBCA) memperlihatkan ketahanan margin bunga bersih serta likuiditas dana murah yang solid pada data keuangan simulasi terbaru.\n\nInvestor pendapatan pasif biasanya memantau rasio pembayaran dividen (payout ratio) serta konsistensi pembagian dividen interim dan final dalam menilai keberlanjutan imbal hasil tunai.",
                'source_name' => 'NADI Editorial Brief (Demo)',
                'category' => 'dividend',
                'country_code' => 'ID',
                'language' => 'id',
                'impact_tag' => 'positive',
                'hours_ago' => 3,
                'symbols' => ['BBCA'],
            ],
            [
                'slug' => 'demo-idx-banks-macro-bi-rate-watch',
                'title' => 'Pantauan Suku Bunga Acuan & Likuiditas Perbankan Besar IDX (Demo)',
                'summary' => 'Arah suku bunga domestik dan kurs IDR menjadi katalis utama bagi valuasi bank berkapitalisasi besar seperti BBCA, BMRI, dan BBRI.',
                'body' => "Ringkasan Editorial Demo: Pergerakan imbal hasil obligasi pemerintah dan kebijakan likuiditas bank sentral memengaruhi biaya dana perbankan besar Indonesia.\n\nPerhatikan perbedaan sensitivitas antara bank berbasis nasabah ritel-korporasi dan segmen mikro dalam menilai risiko kualitas aset.",
                'source_name' => 'NADI Macro Desk (Demo)',
                'category' => 'macro',
                'country_code' => 'ID',
                'language' => 'id',
                'impact_tag' => 'neutral',
                'hours_ago' => 8,
                'symbols' => ['BBCA', 'BBRI', 'BMRI'],
            ],
            [
                'slug' => 'demo-idx-tlkm-infra-data-center',
                'title' => 'TLKM Percepat Monetisasi Infrastruktur Data Center & Konektivitas B2B (Demo)',
                'summary' => 'Ekspansi kapasitas pusat data dan efisiensi belanja modal seluler diarahkan untuk menjaga arus kas bebas serta dividen tahunan.',
                'body' => "Ringkasan Editorial Demo: Telkom Indonesia (TLKM) menjaga arus kas operasional untuk mendukung belanja modal jaringan fiber sekaligus mempertahankan pembagian dividen kas.\n\nRisiko utama yang dipantau adalah tekanan ARPU layanan seluler serta realisasi pertumbuhan kontribusi segmen non-seluler.",
                'source_name' => 'NADI Editorial Brief (Demo)',
                'category' => 'earnings',
                'country_code' => 'ID',
                'language' => 'id',
                'impact_tag' => 'positive',
                'hours_ago' => 14,
                'symbols' => ['TLKM'],
            ],
            [
                'slug' => 'demo-idx-adro-usd-dividend-notes',
                'title' => 'ADRO & Sektor Energi: Memahami Sensitivitas Kurs USD dan Siklus Komoditas (Demo)',
                'summary' => 'Pelaporan keuangan dalam USD membuat rasio valuasi dan kapasitas pembagian dividen emiten energi sangat peka terhadap kurs konversi IDR.',
                'body' => "Ringkasan Editorial Demo: Untuk emiten energi IDX yang menyusun laporan keuangan dalam USD seperti ADRO, penyelarasan mata uang sangat penting agar PER, PBV, dan proyeksi dividend yield tetap akurat.\n\nSiklus harga batubara dan transisi belanja modal proyek hilirisasi menjadi pendorong volatilitas imbal hasil.",
                'source_name' => 'NADI Sector Watch (Demo)',
                'category' => 'corporate_action',
                'country_code' => 'ID',
                'language' => 'id',
                'impact_tag' => 'neutral',
                'hours_ago' => 22,
                'symbols' => ['ADRO'],
            ],
            [
                'slug' => 'demo-idx-goto-profitability-volatility',
                'title' => 'Tinjauan Risiko & Volatilitas Saham Teknologi IDX Berprofitabilitas Negatif (Demo)',
                'summary' => 'Saham dengan laba bersih negatif dan volatilitas tinggi memerlukan pembatasan risiko ketat lewat modul Stock Safety.',
                'body' => "Ringkasan Editorial Demo: Emiten teknologi tahap pertumbuhan yang belum membukukan laba positif berulang sering mengalami fluktuasi harga lebih lebar.\n\nNADI mengecualikan perkalian PER pada laba negatif serta mengingatkan pentingnya ukuran posisi konservatif apabila skor risiko Stock Safety meningkat.",
                'source_name' => 'NADI Risk Desk (Demo)',
                'category' => 'regulation',
                'country_code' => 'ID',
                'language' => 'id',
                'impact_tag' => 'caution',
                'hours_ago' => 29,
                'symbols' => ['GOTO'],
            ],
            [
                'slug' => 'demo-us-msft-cloud-ai-capex',
                'title' => 'MSFT Balance AI Infrastructure Capex with Free Cash Flow Margin Stability (Demo)',
                'summary' => 'Simulasi ringkasan Microsoft menyoroti pertumbuhan cloud enterprise sekaligus disiplin belanja modal jangka menengah.',
                'body' => "Demo Briefing: Microsoft Corporation (MSFT) continues expanding enterprise cloud capacity while maintaining strong operating cash flow conversion.\n\nCross-currency portfolio investors in IDR must weigh USD local return alongside USD/IDR FX translation impact.",
                'source_name' => 'NADI Global Tech Brief (Demo)',
                'category' => 'earnings',
                'country_code' => 'US',
                'language' => 'en',
                'impact_tag' => 'positive',
                'hours_ago' => 5,
                'symbols' => ['MSFT'],
            ],
            [
                'slug' => 'demo-us-nvda-semiconductor-cycle',
                'title' => 'NVDA: Mengukur Risiko Konsentrasi Portofolio pada Saham Semikonduktor Beta Tinggi (Demo)',
                'summary' => 'Pertumbuhan pendapatan pusat data mendorong bobot pasar saham semikonduktor, sehingga kontrol konsentrasi & rebalancing menjadi krusial.',
                'body' => "Ringkasan Editorial Demo: Kenaikan harga signifikan pada saham ber-beta tinggi seperti NVDA kerap membuat bobot portofolio melampaui pita toleransi target.\n\nGunakan Risk & Rebalancing Studio untuk mengevaluasi korelasi portofolio dan dampak pengurangan bertahap.",
                'source_name' => 'NADI Quantitative Desk (Demo)',
                'category' => 'market_recap',
                'country_code' => 'US',
                'language' => 'id',
                'impact_tag' => 'neutral',
                'hours_ago' => 11,
                'symbols' => ['NVDA'],
            ],
            [
                'slug' => 'demo-us-aapl-buyback-and-dividend',
                'title' => 'AAPL Capital Return Focus: Buybacks and Quarterly Dividend Discipline (Demo)',
                'summary' => 'Apple balances quarterly cash dividends with share repurchases, subject to US 15% statutory withholding assumptions for foreign accounts.',
                'body' => "Demo Briefing: US equities typically distribute dividends quarterly, resulting in smoother monthly cash flow curves than single-annual payers.\n\nNon-US investors should account for statutory withholding tax and settlement FX conversion when projecting net cash income.",
                'source_name' => 'NADI Global Income (Demo)',
                'category' => 'dividend',
                'country_code' => 'US',
                'language' => 'en',
                'impact_tag' => 'positive',
                'hours_ago' => 36,
                'symbols' => ['AAPL'],
            ],
            [
                'slug' => 'demo-sgx-dbs-dividend-policy',
                'title' => 'SGX Blue-Chip Banks: DBS (D05) Quarterly Dividend & SGD Stability Appeal (Demo)',
                'summary' => 'Bank Singapura seperti DBS populer untuk pendapatan pasif karena jadwal dividen kuartalan dan pajak pemotongan 0% untuk dividen one-tier.',
                'body' => "Ringkasan Editorial Demo: Saham perbankan utama di SGX (D05, O39, U11) memberi eksposur mata uang SGD sekaligus profil imbal hasil dividen kuartalan yang teratur.\n\nPerhatikan ukuran lot 100 saham di SGX saat menyusun rencana pembelian bertahap.",
                'source_name' => 'NADI ASEAN Brief (Demo)',
                'category' => 'dividend',
                'country_code' => 'SG',
                'language' => 'id',
                'impact_tag' => 'positive',
                'hours_ago' => 42,
                'symbols' => ['D05', 'O39'],
            ],
            [
                'slug' => 'demo-hkex-tencent-china-stimulus',
                'title' => 'HKEX Tech Leaders (0700, 9988): Valuasi & Sensitivitas Kebijakan Konsumsi (Demo)',
                'summary' => 'Saham internet Tiongkok yang tercatat di HKEX terus dipantau dari sisi pemulihan belanja konsumen dan disiplin efisiensi biaya.',
                'body' => "Ringkasan Editorial Demo: Tencent (0700) dan Alibaba (9988) berdenominasi HKD dengan ukuran lot berbeda per emiten (100 lembar).\n\nEvaluasi selalu kelayakan skor kuantitatif dan konfirmasi tren teknikal sebelum mengambil posisi baru.",
                'source_name' => 'NADI Greater China Watch (Demo)',
                'category' => 'market_recap',
                'country_code' => 'HK',
                'language' => 'id',
                'impact_tag' => 'neutral',
                'hours_ago' => 50,
                'symbols' => ['0700', '9988'],
            ],
            [
                'slug' => 'demo-sse-moutai-consumer-staples',
                'title' => 'SSE Consumer Staples (600519): Arus Kas Konsisten di Pasar Tiongkok Daratan (Demo)',
                'summary' => 'Kweichow Moutai (600519) menjadi acuan sektor barang konsumsi primer berdenominasi CNY di bursa Shanghai.',
                'body' => "Ringkasan Editorial Demo: Emiten consumer staples terkemuka di SSE umumnya dinilai melalui stabilitas margin laba bersih, perputaran kas operasional, dan rasio utang yang rendah.",
                'source_name' => 'NADI Greater China Watch (Demo)',
                'category' => 'earnings',
                'country_code' => 'CN',
                'language' => 'id',
                'impact_tag' => 'neutral',
                'hours_ago' => 64,
                'symbols' => ['600519'],
            ],
            [
                'slug' => 'demo-global-fx-idr-usd-portfolio-impact',
                'title' => 'Dampak Pergerakan Kurs USD/IDR terhadap Atribusi Portofolio Lintas Negara (Demo)',
                'summary' => 'Apresiasi mata uang asing dapat menambah kontribusi return dalam pelaporan IDR meskipun harga saham lokal bergerak moderat.',
                'body' => "Ringkasan Editorial Demo: Dalam portofolio multi-negara, pemisahan antara efek harga lokal dan efek translasi mata uang FX membantu investor memahami sumber keuntungan sebenarnya secara transparan.",
                'source_name' => 'NADI Macro Desk (Demo)',
                'category' => 'macro',
                'country_code' => 'ID',
                'language' => 'id',
                'impact_tag' => 'neutral',
                'hours_ago' => 72,
                'symbols' => ['BBCA', 'MSFT'],
            ],
        ];

        foreach ($articles as $item) {
            $article = NewsArticle::query()->updateOrCreate(
                ['slug' => $item['slug']],
                [
                    'data_source_id' => $source->id,
                    'external_id' => $item['slug'],
                    'source_name' => $item['source_name'],
                    'title' => $item['title'],
                    'summary' => $item['summary'],
                    'body' => $item['body'],
                    'url' => null,
                    'category' => $item['category'],
                    'country_code' => $item['country_code'],
                    'language' => $item['language'],
                    'impact_tag' => $item['impact_tag'],
                    'published_at' => $now->subHours($item['hours_ago']),
                    'is_demo' => true,
                ],
            );

            $instIds = [];
            foreach ($item['symbols'] as $sym) {
                if (isset($instruments[$sym])) {
                    $instIds[] = $instruments[$sym]->id;
                }
            }
            $article->instruments()->sync($instIds);
        }

        $admin = User::query()->where('email', 'admin@nadi.test')->first();
        if (! $admin || ResearchNote::query()->where('user_id', $admin->id)->exists()) {
            return;
        }

        $notes = [
            [
                'symbol' => 'BBCA',
                'title' => 'Tesis Inti BBCA: Pertumbuhan CASA & Kualitas Kredit Jangka Panjang (Demo)',
                'template_type' => 'thesis',
                'conviction' => 'high',
                'stance' => 'accumulate',
                'horizon' => 'long_term',
                'target_mult' => 1.15,
                'summary' => 'Posisi inti sektor perbankan IDX dengan keunggulan dana murah dan ketahanan margin bersih.',
                'thesis_body' => "## Ringkasan Tesis\nBBCA dipertahankan sebagai jangkar alokasi ekuitas domestik berkat rasio CASA yang tinggi, efisiensi operasional, serta rekam jejak kualitas aset lintas siklus suku bunga.\n\n## Fokus Pemantauan\n1. Pertumbuhan CASA YoY & rasio kredit terhadap dana (LDR).\n2. Konsistensi pembagian dividen final dan rasio pembayaran.",
                'catalysts' => 'Penurunan bertahap suku bunga acuan domestik dan akselerasi transaksi pembayaran digital.',
                'risks' => 'Valuasi PBV premium sensitif terhadap perlambatan laba bersih atau arus keluar dana asing.',
                'invalidation_criteria' => 'Penurunan kualitas aset (NPL meningkat signifikan > 3%) atau erosi CASA struktural selama 2 kuartal beruntun.',
                'tags' => ['core', 'idx', 'dividend', 'financials'],
                'is_pinned' => true,
            ],
            [
                'symbol' => 'MSFT',
                'title' => 'Review Valuasi MSFT & Eksposur USD dalam Portofolio Global (Demo)',
                'template_type' => 'valuation',
                'conviction' => 'medium',
                'stance' => 'hold',
                'horizon' => 'long_term',
                'target_mult' => 1.10,
                'summary' => 'Kombinasi arus kas cloud enterprise dan lindung nilai alami kurs USD terhadap IDR.',
                'thesis_body' => "## Catatan Valuasi\nMSFT menyeimbangkan pertumbuhan segmen produktivitas/cloud dengan belanja modal infrastruktur pusat data.\n\nDalam kerangka portofolio berbasis IDR, saham ini juga berfungsi sebagai aset diversifikasi mata uang USD.",
                'catalysts' => 'Ekspansi margin operasional pasca gelombang belanja modal awal infrastruktur cloud.',
                'risks' => 'Kompresi multipel PER bila pertumbuhan pendapatan cloud melambat di bawah konsensus.',
                'invalidation_criteria' => 'Free Cash Flow tertekan di bawah kebutuhan belanja modal tanpa peningkatan imbal hasil modal.',
                'tags' => ['global', 'usd', 'cloud'],
                'is_pinned' => true,
            ],
            [
                'symbol' => 'TLKM',
                'title' => 'Checklist Risiko & Dividen TLKM Pasca Konsolidasi Seluler (Demo)',
                'template_type' => 'risk_checklist',
                'conviction' => 'medium',
                'stance' => 'watchlist',
                'horizon' => 'swing',
                'target_mult' => 1.12,
                'summary' => 'Memantau stabilisasi ARPU seluler dan potensi dividend yield tinggi di IDX.',
                'thesis_body' => "## Poin Evaluasi\nTLKM menawarkan profil defensif dengan dividend payout tinggi, namun pergerakan harga bergantung pada pemulihan ARPU data dan efisiensi belanja modal B2B.",
                'catalysts' => 'Disiplin harga paket data industri dan monetisasi segmen infrastruktur/data center.',
                'risks' => 'Kompetisi tarif data yang berkepanjangan dan stagnasi EBITDA margin.',
                'invalidation_criteria' => 'Penurunan laba bersih YoY lebih dari 15% atau pemangkasan dividen di luar ekspektasi.',
                'tags' => ['dividend', 'idx', 'defensive'],
                'is_pinned' => false,
            ],
        ];

        foreach ($notes as $n) {
            $inst = $instruments->get($n['symbol']);
            $close = $inst?->latestQuote ? (float) $inst->latestQuote->close : null;
            $targetPx = $close ? round($close * $n['target_mult'], $inst->currency === 'IDR' ? 0 : 2) : null;

            ResearchNote::query()->create([
                'user_id' => $admin->id,
                'instrument_id' => $inst?->id,
                'title' => $n['title'],
                'template_type' => $n['template_type'],
                'conviction' => $n['conviction'],
                'stance' => $n['stance'],
                'target_price' => $targetPx !== null ? number_format($targetPx, 6, '.', '') : null,
                'target_currency' => $inst?->currency,
                'horizon' => $n['horizon'],
                'summary' => $n['summary'],
                'thesis_body' => $n['thesis_body'],
                'catalysts' => $n['catalysts'],
                'risks' => $n['risks'],
                'invalidation_criteria' => $n['invalidation_criteria'],
                'tags' => $n['tags'],
                'is_pinned' => $n['is_pinned'],
                'reviewed_at' => $now->toDateString(),
                'is_demo' => true,
            ]);
        }
    }
}
