package site.kasjo.edc;

import android.bluetooth.BluetoothAdapter;
import android.bluetooth.BluetoothDevice;
import android.bluetooth.BluetoothSocket;
import android.util.Log;

import org.json.JSONArray;
import org.json.JSONObject;

import java.io.OutputStream;
import java.lang.reflect.Method;
import java.util.Set;
import java.util.UUID;

/**
 * Printing over Bluetooth Classic (SPP) to a paired printer — for terminals whose built-in printer
 * is reached over Bluetooth, and for external thermal printers. The web app sends ready ESC/POS bytes.
 * All methods block: run them on EdcCore's worker thread.
 */
final class BtPrinter {

    private static final String TAG = "KasjoBt";
    private static final UUID SPP = UUID.fromString("00001101-0000-1000-8000-00805F9B34FB");

    /** Paired devices: {ok, devices:[{name, addr, cls}]} */
    static JSONObject list() {
        BluetoothAdapter a = BluetoothAdapter.getDefaultAdapter();
        if (a == null) return EdcCore.fail("Mesin ini tidak punya Bluetooth.");
        try {
            if (!a.isEnabled()) {
                a.enable();
                for (int i = 0; i < 25 && !a.isEnabled(); i++) Thread.sleep(200);
                if (!a.isEnabled()) return EdcCore.fail("Bluetooth mati. Nyalakan Bluetooth di mesin, lalu coba lagi.");
            }
            JSONArray arr = new JSONArray();
            Set<BluetoothDevice> bonded = a.getBondedDevices();
            if (bonded != null) for (BluetoothDevice d : bonded) {
                JSONObject o = new JSONObject();
                o.put("name", d.getName() == null ? d.getAddress() : d.getName());
                o.put("addr", d.getAddress());
                o.put("cls", d.getBluetoothClass() == null ? 0 : d.getBluetoothClass().getMajorDeviceClass());
                arr.put(o);
            }
            JSONObject r = EdcCore.ok();
            r.put("devices", arr);
            return r;
        } catch (SecurityException e) {
            return EdcCore.fail("Izin Bluetooth belum diberikan.");
        } catch (Exception e) {
            PaxPrinter.lastError = "bt list: " + PaxPrinter.describe(e);
            return EdcCore.fail("Daftar Bluetooth tidak bisa dibaca.");
        }
    }

    static JSONObject print(String addr, byte[] data) {
        BluetoothAdapter a = BluetoothAdapter.getDefaultAdapter();
        if (a == null) return EdcCore.fail("Mesin ini tidak punya Bluetooth.");
        if (addr == null || !BluetoothAdapter.checkBluetoothAddress(addr)) return EdcCore.fail("Pilih printer Bluetooth dulu.");
        BluetoothSocket s = null;
        try {
            if (!a.isEnabled()) {
                a.enable();
                for (int i = 0; i < 25 && !a.isEnabled(); i++) Thread.sleep(200);
            }
            BluetoothDevice d = a.getRemoteDevice(addr);
            try { a.cancelDiscovery(); } catch (Exception ignored) { }
            s = connect(d);
            OutputStream os = s.getOutputStream();
            for (int i = 0; i < data.length; i += 512) {
                os.write(data, i, Math.min(512, data.length - i));
                os.flush();
                Thread.sleep(20);
            }
            // give the printer time to take everything before the link closes
            Thread.sleep(400 + Math.min(4000, data.length / 4));
            return EdcCore.ok();
        } catch (SecurityException e) {
            return EdcCore.fail("Izin Bluetooth belum diberikan.");
        } catch (Exception e) {
            PaxPrinter.lastError = "bt print: " + PaxPrinter.describe(e);
            Log.e(TAG, "print", e);
            return EdcCore.fail("Printer Bluetooth tidak bisa disambungkan. Pastikan printer menyala dan sudah dipasangkan, lalu coba lagi.");
        } finally {
            if (s != null) try { s.close(); } catch (Exception ignored) { }
        }
    }

    /** Standard SPP first, then the insecure and channel-1 fallbacks many thermal printers need. */
    private static BluetoothSocket connect(BluetoothDevice d) throws Exception {
        Exception last = null;
        for (int attempt = 0; attempt < 3; attempt++) {
            BluetoothSocket s = null;
            try {
                if (attempt == 0) s = d.createRfcommSocketToServiceRecord(SPP);
                else if (attempt == 1) s = d.createInsecureRfcommSocketToServiceRecord(SPP);
                else {
                    Method m = d.getClass().getMethod("createRfcommSocket", int.class);
                    s = (BluetoothSocket) m.invoke(d, 1);
                }
                s.connect();
                return s;
            } catch (Exception e) {
                last = e;
                if (s != null) try { s.close(); } catch (Exception ignored) { }
            }
        }
        throw last;
    }
}
