# گردش‌کار و وضعیت‌ها

`Didar_Workflow_Manager` برای هر `form_type` workflow شامل `pipeline_id` و statusها را می‌خواند. هر status کلید پایدار، label، `stage_id`، ترتیب و امکان `is_default` دارد. mapping معکوس pipeline/stage نیز برای webhook وجود دارد.

وضعیت داخلی در `_didar_internal_status` و وضعیت عمومی در `_didar_public_status` (با سازگاری `_didar_status`) نگهداری می‌شود. مسئول در `_didar_assigned_user_id` است؛ امکان تخصیص به capability نیاز دارد. اگر workflow همان فرم ناقص باشد، sync به fallback قدیمی نمی‌رود و pending/retry می‌ماند.
