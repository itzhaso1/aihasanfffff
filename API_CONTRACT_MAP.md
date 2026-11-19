# خريطة عقود واجهات البرمجة بين Laravel و Flutter
# HASIM AI — API Contract & Schema Mapping Specification

**الإصدار:** Mobile API V1 (`/api/mobile/v1/*`)  
**البروتوكول الأساسي:** HTTPS / RESTful JSON + WebSocket (Pusher / Reverb)  
**المصادقة:** Laravel Sanctum Bearer Token (`Authorization: Bearer <token>`)  
**عزل المستأجر:** ترويسة مساحة العمل الإلزامية (`X-Workspace-Id: <id>`)

---

## 1. الترويسات القياسية المشتركة (Standard Request & Response Headers)

### ترويسات الطلب (Client Request Headers):
```http
Accept: application/json
Content-Type: application/json
Authorization: Bearer {sanctum_token}
X-Workspace-Id: {workspace_id}
X-App-Version: 1.0.0
X-Device-Id: {device_uuid}
```

### مغلف الاستجابة القياسي (Standard Envelope Structure):
```json
{
  "success": true,
  "message": "تمت العملية بنجاح",
  "data": { ... },
  "meta": {
    "current_page": 1,
    "last_page": 5,
    "per_page": 20,
    "total": 95
  }
}
```

---

## 2. جدول العقود التفصيلي للواجهات المضافة والمحدثة

### 2.1 وحدة المحادثات (Conversations)

#### إغلاق محادثة (Close Conversation)
- **الطريقة والمحدد:** `POST /api/mobile/v1/conversations/{id}/close`
- **المصادقة والصلاحية:** Sanctum Token، عضو مساحة العمل (`agent` فأعلى).
- **العزل (Scope):** التحقق من تبعية المحادثة لنفس `workspace_id`؛ وإلا يُرجع `404 Not Found`.
- **معاملات الرأس والمسار:** `{id}` معرف المحادثة (عدد صحيح).
- **الاستجابة الناجحة (`200 OK`):**
  ```json
  {
    "success": true,
    "message": "تم إغلاق المحادثة بنجاح",
    "data": {
      "id": 12,
      "status": "closed",
      "updated_at": "2026-11-19T10:30:00Z"
    }
  }
  ```
- **البث اللحظي المصاحب:** يبث حدث `conversation.updated` مع `status: "closed"` إلى قناة `private-workspace.{workspaceId}`.
- **نموذج Flutter المقابل:** `ConversationRepository.closeConversation(int id)` + تحديث `ConversationModel.copyWith(status: 'closed')`.

#### إعادة فتح محادثة (Reopen Conversation)
- **الطريقة والمحدد:** `POST /api/mobile/v1/conversations/{id}/reopen`
- **المصادقة والصلاحية:** Sanctum Token، عضو مساحة العمل.
- **الاستجابة الناجحة (`200 OK`):**
  ```json
  {
    "success": true,
    "message": "تم إعادة فتح المحادثة بنجاح",
    "data": {
      "id": 12,
      "status": "open",
      "updated_at": "2026-11-19T10:31:00Z"
    }
  }
  ```
- **نموذج Flutter المقابل:** `ConversationRepository.reopenConversation(int id)` + `ConversationModel.copyWith(status: 'open')`.

---

### 2.2 وحدة فريق العمل والموظفين (Workforce & Employees)

#### استعراض أعضاء مساحة العمل والدعوات (List Employees & Invitations)
- **الطريقة والمحدد:** `GET /api/mobile/v1/employees`
- **المصادقة والصلاحية:** Sanctum Token، عضو مساحة العمل.
- **الاستجابة الناجحة (`200 OK`):**
  ```json
  {
    "success": true,
    "data": {
      "employees": [
        {
          "id": 1,
          "user_id": 101,
          "name": "عبدالله المدير",
          "email": "abdullah@example.com",
          "phone": "+966500000001",
          "avatar_url": "https://.../avatars/1.png",
          "membership_role": "admin",
          "status": "active",
          "is_owner": false,
          "created_at": "2026-01-15T08:00:00Z"
        }
      ],
      "invitations": [
        {
          "id": 5,
          "email": "agent@example.com",
          "role": "agent",
          "status": "pending",
          "expires_at": "2026-12-01T00:00:00Z",
          "created_at": "2026-11-15T09:00:00Z"
        }
      ],
      "assignable_roles": [
        "admin",
        "manager",
        "agent",
        "accountant",
        "receptionist",
        "staff"
      ]
    }
  }
  ```
- **نموذج Flutter المقابل:** `EmployeeMemberModel`, `EmployeeInvitationModel` عبر `EmployeeRepository.list()`.

#### دعوة موظف جديد (Invite Employee)
- **الطريقة والمحدد:** `POST /api/mobile/v1/employees/invite`
- **المصادقة والصلاحية:** Sanctum Token، تتطلب دور `owner` أو `admin`.
- **جسم الطلب (Request Body):**
  ```json
  {
    "email": "new.member@example.com",
    "role": "agent"
  }
  ```
- **قواعد التحقق (Validation):**
  - `email`: مطلوب، صيغة بريد صحيحة، غير مسجل مسبقاً في مساحة العمل.
  - `role`: مطلوب، قيمة صالحة من `assignable_roles`.
- **الاستجابة الناجحة (`201 Created`):**
  ```json
  {
    "success": true,
    "message": "تم إرسال دعوة الانضمام بنجاح",
    "data": {
      "id": 6,
      "email": "new.member@example.com",
      "role": "agent",
      "status": "pending",
      "created_at": "2026-11-19T11:00:00Z"
    }
  }
  ```

#### تعديل صلاحية موظف (Update Employee Role)
- **الطريقة والمحدد:** `PATCH /api/mobile/v1/employees/{id}`
- **المصادقة والصلاحية:** دور `owner` أو `admin`، لا يمكن تعديل صلاحية المالك (`owner`).
- **جسم الطلب:**
  ```json
  {
    "membership_role": "manager"
  }
  ```
- **الاستجابة الناجحة (`200 OK`):**
  ```json
  {
    "success": true,
    "message": "تم تحديث دور الموظف بنجاح",
    "data": {
      "id": 1,
      "membership_role": "manager"
    }
  }
  ```

#### حذف موظف من مساحة العمل (Remove Employee)
- **الطريقة والمحدد:** `DELETE /api/mobile/v1/employees/{id}`
- **المصادقة والصلاحية:** دور `owner` أو `admin`، يمنع حذف المالك الأصلي للمساحة.
- **الاستجابة الناجحة (`200 OK`):**
  ```json
  {
    "success": true,
    "message": "تم حذف الموظف من مساحة العمل بنجاح"
  }
  ```

---

### 2.3 وحدة التحليلات (Analytics & Reports)

#### استعراض ملخص التحليلات (Get Analytics Summary)
- **الطريقة والمحدد:** `GET /api/mobile/v1/analytics`
- **المصادقة والصلاحية:** Sanctum Token، عضو مساحة العمل.
- **معاملات الاستعلام (Query Parameters):**
  - `range`: اختياري، القيم المتاحة: `today`, `week`, `month`, `year`, `custom` (الافتراضي: `month`).
  - `from`: اختياري في حالة النطاق المخصص (`YYYY-MM-DD`).
  - `to`: اختياري في حالة النطاق المخصص (`YYYY-MM-DD`).
- **الاستجابة الناجحة (`200 OK`):**
  ```json
  {
    "success": true,
    "data": {
      "from": "2026-11-01",
      "to": "2026-11-30",
      "revenue": 45890.00,
      "orders_count": 312,
      "customers_new": 78,
      "conversations_count": 1420,
      "ai_responses_count": 2840,
      "conversion_rate": 21.97,
      "top_products": [
        {
          "name": "باقة العود الملكي",
          "quantity": 140,
          "revenue": 21000.00
        }
      ],
      "timeline": [
        {
          "label": "2026-11-01",
          "orders": 12,
          "revenue": 1800.00
        }
      ],
      "peak_hours": {
        "16": 45,
        "20": 92
      },
      "payment_status_breakdown": {
        "paid": 290,
        "pending": 15,
        "failed": 7
      }
    }
  }
  ```
- **نموذج Flutter المقابل:** `WorkspaceAnalyticsModel` عبر `AnalyticsRepository.summary()`.

---

### 2.4 وحدة البريد الإلكتروني (Email Management)

#### استعراض حسابات البريد (List Email Accounts)
- **الطريقة والمحدد:** `GET /api/mobile/v1/emails/accounts`
- **الاستجابة:** قائمة بحسابات البريد المهيأة لمساحة العمل (`id`, `name`, `email`, `brand_color`).

#### استعراض رسائل مجلد البريد (List Folder Messages)
- **الطريقة والمحدد:** `GET /api/mobile/v1/emails`
- **معاملات الاستعلام:**
  - `folder`: `inbox` (الوارد), `sent` (المرسل), `drafts` (المسودات).
  - `page`: رقم الصفحة.
  - `starred`: تصفية الرسائل المميزة بنجمة (`1` أو `0`).
- **الاستجابة الناجحة (`200 OK`):** قائمة برسائل البريد مع التفاصيل والمرفقات وحالة القراءة.

#### إرسال بريد جديد (Send Email)
- **الطريقة والمحدد:** `POST /api/mobile/v1/emails`
- **جسم الطلب:**
  ```json
  {
    "email_account_id": 1,
    "to": "client@example.com",
    "subject": "تأكيد موعد الشحن",
    "body": "مرحباً، تم تأكيد شحن طلبكم بنجاح.",
    "reply_to_message_id": null
  }
  ```
- **نموذج Flutter المقابل:** `EmailRepository.send()` + `EmailComposeScreen`.

---

### 2.5 وحدة حذف العميل (Customer Deletion)

#### حذف عميل من مساحة العمل (Destroy Customer)
- **الطريقة والمحدد:** `DELETE /api/mobile/v1/customers/{id}`
- **المصادقة والصلاحية:** Sanctum Token، عضو مساحة العمل بصلاحية التعديل.
- **العزل (Tenancy):** يتم فحص العميل والتأكد من انتمائه لـ `workspace_id` الحالي؛ إن كان ينتمي لمساحة عمل أخرى يُرجع النظام `404 Not Found` فوراً دون أي تعديل.
- **الاستجابة الناجحة (`200 OK`):**
  ```json
  {
    "success": true,
    "message": "تم حذف العميل بنجاح"
  }
  ```
- **نموذج Flutter المقابل:** `CustomerRepository.delete(int id)` مع نافذة تأكيد الحذف في `CustomerInfoDrawer`.
