$ErrorActionPreference = "Stop"

git fetch origin
if ($LASTEXITCODE -ne 0) { throw "فشل جلب آخر تعديلات GitHub" }

$status = git status --porcelain
if (-not $status) {
    Write-Host "لا توجد تعديلات لرفعها."
    exit 0
}

git pull --rebase origin main
if ($LASTEXITCODE -ne 0) { throw "توقّف: توجد تعارضات تحتاج حلًا." }

git add -u
git add apps

git diff --cached --quiet
if ($LASTEXITCODE -eq 0) {
    Write-Host "لا توجد تغييرات مجهزة للرفع."
    exit 0
}

git diff --cached --name-only
Write-Host "راجع قائمة الملفات أعلاه قبل المتابعة."
$confirm = Read-Host "اكتب YES للمتابعة ورفع الملفات المذكورة"
if ($confirm -ne "YES") { git reset; Write-Host "تم الإلغاء."; exit 0 }

git commit -m "Update project"
if ($LASTEXITCODE -ne 0) { throw "فشل إنشاء commit" }

git push origin main
if ($LASTEXITCODE -ne 0) { throw "فشل الرفع؛ راجع رسالة Git." }

Write-Host "تم رفع التعديلات بنجاح."
