# Toko Kita — Operasional Toko

Dashboard sederhana untuk melihat data penjualan, pelanggan, produk, dan transaksi toko. Aplikasi dibuat sebagai satu halaman HTML dan dapat dijalankan langsung di browser tanpa instalasi atau server.

## Menjalankan aplikasi

1. Buka folder proyek.
2. Buka `toko.html` di browser seperti Chrome, Edge, atau Firefox.
3. Gunakan menu **Ringkasan**, **Pelanggan**, **Produk**, **Transaksi**, dan **Laporan** untuk berpindah halaman.

Tidak diperlukan Node.js, database, atau proses build. Koneksi internet hanya diperlukan untuk memuat font dari Google Fonts; aplikasi tetap dapat digunakan dengan font pengganti jika sedang offline.

## Fitur

- **Ringkasan:** total penjualan, jumlah transaksi dan pelanggan aktif, penjualan per kota, produk terlaris, dan peringatan stok.
- **Pelanggan:** cari dan filter pelanggan, lalu buka nama pelanggan untuk melihat riwayat belanjanya.
- **Produk:** cari produk, filter kategori, lihat harga dan stok, serta tampilkan produk yang perlu di-restok.
- **Transaksi:** lihat transaksi dan detail barang yang dibeli, atau buat transaksi baru dengan satu atau lebih produk.
- **Validasi penjualan:** jumlah barang harus valid dan stok harus mencukupi sebelum transaksi disimpan. Total transaksi dihitung otomatis dan stok diperbarui.
- **Laporan:** ringkasan belanja pelanggan dan analisis transaksi.
- **Ekspor CSV:** unduh data transaksi beserta rincian produk untuk dibuka di aplikasi spreadsheet.
- **Reset data:** pulihkan transaksi dan stok ke data contoh awal setelah konfirmasi.
- **Responsif:** tampilan menyesuaikan layar komputer dan ponsel.

## Penyimpanan data

Data awal merupakan data contoh yang tertanam di `toko.html`. Perubahan transaksi dan stok disimpan di **localStorage browser** pada perangkat yang digunakan. Data ini tidak dikirim ke server dan tidak otomatis tersinkron ke perangkat atau pengguna lain.

Menghapus data situs/browser dapat menghapus perubahan yang tersimpan. Gunakan ekspor CSV untuk menyimpan salinan data transaksi. Tombol reset akan menghapus perubahan lokal dan mengembalikan data contoh.

## Teknologi

- HTML, CSS, dan JavaScript
- Tanpa framework atau dependensi yang perlu dipasang
- Penyimpanan lokal melalui Web Storage API
