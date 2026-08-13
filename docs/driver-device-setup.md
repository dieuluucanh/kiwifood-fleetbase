# Driver phone setup checklist — keep location tracking alive

**Why:** when a driver's marker freezes on the console map, the most common
cause is the *phone* stopping the Navigator app in the background (battery
management or force-closing the app) — not the server. Follow this checklist
on every driver phone once, then verify during the first shift.

---

## Android (all drivers)

1. **Battery: Unrestricted**
   Settings → Apps → Navigator → Battery → choose **Unrestricted** / **No restrictions**.
2. **Autostart (Xiaomi / Oppo / Vivo / Huawei)**
   Settings → Apps → Navigator → enable **Autostart** (a.k.a. "App launch → Manage manually → allow all").
3. **Samsung only:** Settings → Battery → Background usage limits → make sure
   Navigator is **not** in "Sleeping apps" or "Deep sleeping apps".
4. **Location permission:** Settings → Apps → Navigator → Permissions →
   Location → **Allow all the time** + enable **Use precise location**.
5. **Never force-stop the app** (Settings → Apps → Force stop kills tracking
   until the app is manually reopened).
6. Brand-specific guides if problems persist: <https://dontkillmyapp.com>

## iPhone (iOS)

1. Settings → Navigator → Location → **Always** + **Precise Location** on.
2. Settings → Navigator → **Motion & Fitness** on.
3. Settings → General → Background App Refresh → on for Navigator.
4. **Low Power Mode off** during shifts.
5. **Never swipe-up-close Navigator.** This is the #1 iOS cause: after a
   force-quit, iOS does NOT restart location tracking — the driver must open
   the app again manually. Just leave the app in the app switcher.

## Shift routine (ops policy)

- Start of shift: open Navigator → tap the check-in (tracking) toggle →
  confirm with the dispatcher that you appear **online** on the console.
- During shift: leave Navigator running (do not force-close). You may use
  other apps normally.
- If you accidentally closed it: reopen Navigator once — tracking resumes.

---

# Hướng dẫn cài đặt điện thoại cho tài xế — giữ định vị luôn hoạt động

**Lý do:** khi vị trí tài xế trên bản đồ bị "đứng im", nguyên nhân thường là
*điện thoại* đã tắt ứng dụng Navigator chạy nền (tiết kiệm pin hoặc vuốt tắt
app) — không phải lỗi hệ thống. Làm checklist này một lần cho mỗi điện thoại.

## Android

1. **Pin: Không hạn chế** — Cài đặt → Ứng dụng → Navigator → Pin → chọn
   **Không hạn chế / Không giới hạn**.
2. **Tự khởi động (Xiaomi / Oppo / Vivo):** bật **Autostart / Tự động khởi động**
   cho Navigator.
3. **Samsung:** Cài đặt → Pin → Giới hạn chạy nền → đảm bảo Navigator **không**
   nằm trong "Ứng dụng ngủ" hay "Ứng dụng ngủ sâu".
4. **Quyền vị trí:** Cài đặt → Ứng dụng → Navigator → Quyền → Vị trí →
   **Luôn cho phép** + bật **Vị trí chính xác**.
5. **Không bấm "Buộc dừng" (Force stop)** ứng dụng — nếu bấm, định vị chỉ hoạt
   động lại khi mở lại app bằng tay.

## iPhone (iOS)

1. Cài đặt → Navigator → Vị trí → **Luôn cho phép** + bật **Vị trí chính xác**.
2. Bật **Chuyển động & Thể dục** (Motion & Fitness).
3. Bật **Làm mới ứng dụng trong nền** cho Navigator.
4. **Tắt Chế độ nguồn điện thấp** khi đi làm.
5. **Tuyệt đối không vuốt lên để tắt Navigator.** Đây là nguyên nhân số 1 trên
   iPhone: sau khi vuốt tắt, iOS sẽ KHÔNG bật lại định vị — phải mở lại app
   bằng tay. Cứ để app trong danh sách đa nhiệm.

## Quy trình ca làm việc

- Đầu ca: mở Navigator → bật nút check-in (tracking) → xác nhận với điều phối
  viên rằng bạn đang **online** trên bản đồ.
- Trong ca: để Navigator chạy nền, không vuốt tắt. Dùng các app khác bình thường.
- Nếu lỡ tay tắt app: mở lại Navigator một lần là định vị hoạt động lại.
