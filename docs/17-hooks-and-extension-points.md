# Hookها و نقاط توسعه

| Hook مصرف‌شده | کاربرد |
|---|---|
| `user_register`، `register_new_user`، `added_user_meta`، `updated_user_meta` | صف Person sync |
| `rest_api_init` | ثبت webhook |
| `cron_schedules` | interval پنج‌دقیقه‌ای |
| `before_delete_post`، `pre_delete_post`، `pre_trash_post` | حفاظت/ثبت lifecycle |
| `posts_search` | جست‌وجوی درخواست |
| `update_option_didar_settings` | حفاظت storage فایل |

| Hook منتشرشده | پارامتر |
|---|---|
| `didar_submission_created` | `$post_id` |
| `didar_submission_updated` | `$post_id` |
| `didar_submission_workflow_changed` | `$post_id, $changed_keys` |

Cronهای افزونه `didar_process_sync`، `didar_process_user_sync`، `didar_cleanup_temporary_uploads` و `didar_backfill_last_updated` هستند. shortcodeها و AJAX endpointها public extension API رسمی اعلام نشده‌اند؛ به signature داخلی آن‌ها وابستگی سخت ندهید.
