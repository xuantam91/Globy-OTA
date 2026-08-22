# Globy OTA Server

Hệ thống quản lý và cập nhật phần mềm từ xa (OTA - Over-the-Air) dành cho các thiết bị loa thông minh Globy.

## Tính năng chính

- **Cập nhật Firmware**: Phát hành và quản lý phiên bản Firmware (`.bin`) cho từng Model/Board.
- **Cập nhật Assets**: Phát hành các gói tài nguyên (`assets.bin` bao gồm giao diện, font, ngôn ngữ, hình ảnh).
- **Ép buộc cập nhật thiết bị (Force Overrides)**: Cho phép cấu hình cập nhật thử nghiệm hoặc khôi phục cho một thiết bị cụ thể dựa trên địa chỉ MAC hoặc UUID mà không ảnh hưởng tới các thiết bị khác.
- **Quản lý Boards động**: Giao diện Admin cho phép thêm, sửa, xóa các model và board code tương ứng một cách dễ dàng mà không cần can thiệp vào mã nguồn.
- **Bảo mật & Phân quyền**: Đăng nhập trang quản trị sử dụng cơ chế bảo mật session và lưu trữ tài khoản mã hóa bằng mã hash của PHP (`bcrypt`).

## Cấu trúc thư mục

```text
├── admin.php               # Trang quản trị chính (Dashboard)
├── login.php               # Trang đăng nhập quản trị viên
├── auth.php                # Các hàm xử lý xác thực & phiên làm việc (Session)
├── auth_users.json         # Cơ sở dữ liệu tài khoản (Mật khẩu được băm bảo mật)
├── index.php               # API endpoint tiếp nhận request OTA từ thiết bị
├── models.json             # Danh sách Model loa và Board Code tương ứng
├── ota_config.json         # Cấu hình các phiên bản firmware hiện tại
├── ota_assets_config.json  # Cấu hình các phiên bản assets hiện tại
├── device_overrides.json   # Cấu hình ép cập nhật (Firmware) cho từng thiết bị
├── asset_overrides.json    # Cấu hình ép cập nhật (Assets) cho từng thiết bị
├── version_history.json    # Lịch sử các lần phát hành firmware
├── ota_fw/                 # [Chưa đẩy] Thư mục chứa các file firmware (.bin)
└── ota_assets/             # [Chưa đẩy] Thư mục chứa các file assets (.bin)
```

## Hướng dẫn cài đặt & Cấu hình

### 1. Yêu cầu hệ thống
- Máy chủ web hỗ trợ PHP 7.4 trở lên (Nginx hoặc Apache).
- Kích hoạt HTTPS để đảm bảo bảo mật kết nối API và truyền tải dữ liệu.

### 2. Cài đặt ban đầu
1. Clone mã nguồn về máy chủ web của bạn:
   ```bash
   git clone https://github.com/xuantam91/Globy-OTA.git
   ```
2. Đảm bảo các thư mục sau có quyền ghi (`write permission` / chmod 775 hoặc 755 tùy cấu hình server) để PHP có thể lưu file:
   - Thư mục gốc (để ghi các file `.json`)
   - Thư mục `ota_fw/` và `ota_assets/` (nơi lưu trữ firmware upload lên)

3. Tạo các thư mục lưu trữ file nếu chưa có sẵn:
   ```bash
   mkdir -p ota_fw/device_overrides ota_assets/device_overrides
   ```

### 3. Cấu hình Tài khoản quản trị (`auth_users.json`)
File `auth_users.json` lưu trữ thông tin đăng nhập. Để thay đổi mật khẩu hoặc thêm tài khoản mới:
1. Sinh mã băm mật khẩu bảo mật (Bcrypt) bằng cách chạy lệnh PHP dưới đây:
   ```bash
   php -r "echo password_hash('MAT_KHAU_MOI_CUA_BAN', PASSWORD_DEFAULT) . PHP_EOL;"
   ```
2. Mở file `auth_users.json`, cập nhật chuỗi băm nhận được vào trường `"password_hash"` của tài khoản mong muốn.

## API hoạt động (Dành cho Thiết bị Loa)

Thiết bị gửi một request `POST` định kỳ lên endpoint gốc (`/` hoặc `/index.php`) với body JSON mô tả phiên bản hiện tại và thông tin thiết bị:
```json
{
  "mac_address": "b8:f8:62:e7:ab:90",
  "uuid": "xxx-xxx-xxx",
  "application": {
    "version": "2.0.9"
  },
  "board": {
    "type": "luxiaoban-xiaozhi-1.54tft"
  }
}
```

Hệ thống sẽ phản hồi (Response) thông tin cập nhật phù hợp:
- Nếu có cập nhật (hoặc có bản ghi Force Override đang bật): Trả về link download file `.bin`.
- Nếu không có cập nhật: Trả về link rỗng.
