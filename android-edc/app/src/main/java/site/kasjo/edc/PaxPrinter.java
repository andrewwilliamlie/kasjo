package site.kasjo.edc;

import android.content.Context;
import android.util.Log;

import com.pax.dal.IDAL;
import com.pax.dal.IPrinter;
import com.pax.dal.entity.EFontTypeAscii;
import com.pax.dal.entity.EFontTypeExtCode;
import com.pax.neptunelite.api.NeptuneLiteUser;

import java.nio.charset.StandardCharsets;
import java.util.List;

/**
 * Built-in printer of PAX terminals (NeptuneLite API), based on HausJo's ReceiptPrinter sample.
 *
 * init() clears the buffer, fontSet()/printStr() add text, start() prints the buffer and BLOCKS
 * until done. The buffer holds 2048 bytes, so long receipts are printed in parts.
 * Every method here must run on a background thread (EdcBridge uses one worker thread).
 *
 * The web app sends receipts already laid out for 58 mm paper: 32 characters per line,
 * 16 for big lines. Centering is done here.
 */
final class PaxPrinter {

    private static final String TAG = "KasjoPrinter";
    static final int LINE_WIDTH = 32;      // FONT_12_24 on 384 dots
    static final int BIG_LINE_WIDTH = 16;  // FONT_24_48
    private static final int MAX_BUFFER_BYTES = 1800;
    private static final byte LINE_SPACE = 4;

    /** Status used when the PAX SDK is not in this build or the terminal has no PAX service. */
    static final int NO_SDK = -100;
    static final int EXCEPTION = -99;

    static final class Line {
        final String text;
        final boolean center, big;
        Line(String text, boolean center, boolean big) {
            this.text = text == null ? "" : text;
            this.center = center;
            this.big = big;
        }
    }

    private final Context context;
    private IPrinter printer;
    private int bufferedBytes = 0;
    private boolean bigFont = false;

    PaxPrinter(Context context) {
        this.context = context.getApplicationContext();
    }

    /** Creating the DAL is slow, so it is done once, on first use. */
    private IPrinter printer() throws Exception {
        if (printer == null) {
            if (!BuildConfig.HAS_PAX_SDK) throw new IllegalStateException("PAX SDK not in this build");
            IDAL dal = NeptuneLiteUser.getInstance().getDal(context);
            if (dal == null) throw new IllegalStateException("PAX DAL unavailable");
            printer = dal.getPrinter();
            if (printer == null) throw new IllegalStateException("PAX printer unavailable");
        }
        return printer;
    }

    boolean available() {
        try { printer(); return true; } catch (Throwable t) { return false; }
    }

    int status() {
        try { return printer().getStatus(); }
        catch (Throwable t) { Log.w(TAG, "status", t); return code(t); }
    }

    int feed(int dots) {
        try {
            IPrinter p = printer();
            int s = p.getStatus();
            if (s != 0) return s;
            p.init();
            p.step(Math.max(24, Math.min(dots, 600)));
            return p.start();
        } catch (Throwable t) { Log.w(TAG, "feed", t); return code(t); }
    }

    int print(List<Line> lines, int feedDots) {
        try {
            IPrinter p = printer();
            int s = p.getStatus();
            if (s != 0) return s;
            newJob();
            for (Line l : lines) {
                if (l.big != bigFont) { bigFont = l.big; applyFont(); }
                int w = l.big ? BIG_LINE_WIDTH : LINE_WIDTH;
                s = text(l.center ? center(l.text, w) : l.text);
                if (s != 0) return s;
            }
            if (bigFont) { bigFont = false; applyFont(); }
            p.step(Math.max(0, Math.min(feedDots, 600)));
            bufferedBytes += 1;
            return printNow();
        } catch (Throwable t) {
            Log.e(TAG, "print", t);
            return code(t);
        }
    }

    private static int code(Throwable t) {
        return (t instanceof IllegalStateException || t instanceof UnsupportedOperationException
                || t instanceof LinkageError) ? NO_SDK : EXCEPTION;
    }

    private void newJob() throws Exception {
        IPrinter p = printer();
        p.init();
        p.setGray(1);
        p.spaceSet((byte) 0, LINE_SPACE);
        applyFont();
        bufferedBytes = 0;
    }

    private int printNow() throws Exception {
        if (bufferedBytes == 0) return 0;
        int s = printer().start();
        newJob();
        return s;
    }

    private int text(String s) throws Exception {
        String data = s + "\n";
        int size = data.getBytes(StandardCharsets.UTF_8).length;
        if (bufferedBytes + size > MAX_BUFFER_BYTES) {
            int st = printNow();
            if (st != 0) return st;
        }
        printer().printStr(data, null);
        bufferedBytes += size;
        return 0;
    }

    private void applyFont() throws Exception {
        if (bigFont) printer().fontSet(EFontTypeAscii.FONT_24_48, EFontTypeExtCode.FONT_48_48);
        else printer().fontSet(EFontTypeAscii.FONT_12_24, EFontTypeExtCode.FONT_24_24);
    }

    private static String center(String s, int width) {
        s = s.trim();
        if (s.length() >= width) return s;
        StringBuilder b = new StringBuilder();
        for (int i = 0; i < (width - s.length()) / 2; i++) b.append(' ');
        return b.append(s).toString();
    }

    /** Message shown to the agent (Indonesian). Codes from the IPrinter docs. */
    static String message(int status) {
        switch (status) {
            case 0: return "Berhasil";
            case 1: return "Printer sedang sibuk. Tunggu sebentar lalu coba lagi.";
            case 2: return "Kertas habis. Pasang gulungan kertas baru lalu cetak lagi.";
            case 3: return "Format data cetak salah.";
            case 4: return "Printer bermasalah. Matikan dan nyalakan lagi mesin EDC.";
            case 8: return "Printer terlalu panas. Tunggu beberapa menit lalu coba lagi.";
            case 9: return "Baterai lemah. Isi daya mesin EDC dulu.";
            case -2: return "Struk terlalu panjang untuk dicetak.";
            case -4: return "Font printer belum terpasang di mesin ini.";
            case -5: return "Tutup printer terbuka. Tutup dulu lalu coba lagi.";
            case -6: return "Pemotong kertas macet.";
            case -16: return "Pencetakan belum selesai. Coba lagi.";
            case NO_SDK: return "Printer bawaan tidak tersedia di aplikasi atau mesin ini.";
            case EXCEPTION: return "Printer error. Coba lagi.";
            default: return "Printer error (kode " + status + ").";
        }
    }
}
