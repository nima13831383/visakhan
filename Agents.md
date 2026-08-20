# Didar WordPress Plugin — AGENTS.md

## 1. Project Identity

Plugin name: **Didar**

Plugin slug:

```text
didar
```

Text domain:

```text
didar
```

This project is a custom WordPress plugin for managing multiple predefined application/request forms.

The plugin must allow:

1. Logged-in users to submit predefined forms from the frontend.
2. Administrators to manually create the same submissions from WordPress Admin.
3. Administrators to view and edit existing submissions.
4. Users to view their own previous submissions.
5. Some fields to support AJAX file uploads.
6. Every submission to be stored natively in WordPress.

Do not turn this project into a general-purpose form builder unless explicitly requested.

The available Form Types and their fields are predefined by this project.

---

# 2. Source of Truth

The project includes a specification file:

```text
FORMS.txt
```

**Read `FORMS.txt` completely before implementing the form registry.**

It is the authoritative business specification for:

* Form Types
* field labels
* field types
* required/optional fields
* select/radio/checkbox options
* internal option values where explicitly provided
* country lists
* occupation lists
* form-specific business data

Do not silently invent fields that are not specified.

Do not silently remove fields.

Do not rename an explicitly provided field key/value if doing so could break compatibility with existing data.

If the specification contains an obvious typo or ambiguity, preserve compatibility and document the issue instead of silently changing business meaning.

---

# 3. Core Architecture

The system consists of:

```text
Form Definition
       ↓
Form Renderer
       ↓
Validation / Sanitization
       ↓
Submission
       ↓
WordPress Custom Post Type
```

There must be a **single source of truth** for Form Definitions.

Do NOT separately hardcode the same form fields in:

* frontend shortcode
* WordPress Admin
* validation
* AJAX
* display tables

All of them must consume the same Form Registry.

---

# 4. Form Registry

Implement a centralized Form Registry.

Conceptually:

```php
[
    'consultation' => [
        'label' => 'درخواست مشاوره',
        'fields' => [
            // ...
        ],
    ],

    'embassy_appointment' => [
        'label' => 'درخواست وقت سفارت',
        'fields' => [
            // ...
        ],
    ],
]
```

Each field definition should support properties when applicable such as:

```php
[
    'name'        => 'field_name',
    'label'       => '...',
    'type'        => 'text',
    'required'    => true,
    'options'     => [],
    'multiple'    => false,
    'placeholder' => '',
]
```

Additional metadata may be introduced where required.

Examples:

```text
min
max
accept
mime_types
date_format
description
default
conditional
multiple
validation
```

Do not over-engineer the schema before reading all forms in `FORMS.txt`.

---

# 5. Required Form Types

The current specification defines at least these Form Types:

```text
consultation
embassy_appointment
traveler_evaluation
complaint_suggestion
visa_request
```

Suggested Persian labels:

```text
consultation
→ درخواست مشاوره

embassy_appointment
→ درخواست وقت سفارت

traveler_evaluation
→ فرم ارزیابی اطلاعات مسافران ویزاخان

complaint_suggestion
→ ثبت شکایات و پیشنهادات ویزاخان

visa_request
→ درخواست ویزا
```

These internal slugs should remain stable after data exists.

---

# 6. Important Legacy Values

Some fields in `FORMS.txt` explicitly provide internal values.

Preserve those values where compatibility matters.

For example, consultation marital status contains values similar to:

```text
mojarad
motahal
motlage
fothamsar
```

Consultation subjects contain explicitly defined values such as:

```text
torist
tahsil
kari
tamlik
sarmae
melk
```

Consultation type includes:

```text
hosori
telfoni
```

Do not replace explicitly supplied stored values merely because another naming scheme looks cleaner.

Display labels and stored values are different concepts.

---

# 7. Submission Storage

Create one Custom Post Type for all submissions.

Use:

```text
didar_submission
```

A submission represents one completed Form Type.

Do NOT create one CPT per form.

Store the Form Type separately, for example:

```text
_didar_form_type
```

Use WordPress post ownership where appropriate:

```php
post_author
```

to associate frontend submissions with the logged-in user.

Store field values using post metadata unless there is a demonstrated reason to use another WordPress-native mechanism.

Do not create custom database tables unless explicitly requested.

---

# 8. Submission Data

Every submission should make it possible to determine:

```text
Submission ID
Form Type
Owner/User
Creation Date
Last Modified Date
Status
Field Values
Didar File IDs
```

Do not trust client-supplied user IDs.

For frontend submissions, derive ownership from:

```php
get_current_user_id()
```

---

# 9. Submission Status

Support a submission status layer.

Some forms already specify statuses.

For example, the embassy appointment specification includes statuses such as:

```text
در انتظار بررسی
تایید اولیه
نیاز به اصلاح مدارک
تکمیل شده
```

Do not expose administrative status controls to frontend users unless explicitly required.

Status values should use stable internal keys with translatable/display labels.

---

# 10. Frontend Form Shortcode

Implement:

```text
[didar_form type="FORM_TYPE"]
```

Examples:

```text
[didar_form type="consultation"]
```

```text
[didar_form type="embassy_appointment"]
```

```text
[didar_form type="traveler_evaluation"]
```

```text
[didar_form type="complaint_suggestion"]
```

```text
[didar_form type="visa_request"]
```

The shortcode must:

1. Check authentication if the form is restricted to logged-in users.
2. Validate `type`.
3. Retrieve the Form Definition from the registry.
4. Render only fields belonging to that Form Type.
5. Render correct field controls.
6. Mark required fields.
7. Render predefined options.
8. Include CSRF protection.
9. Support validation errors.
10. Support AJAX file fields.
11. Save the submission securely.
12. Associate it with the current user.
13. Return a clear success/error result.

Never determine valid fields from arbitrary browser input.

The server-side Form Registry is authoritative.

---

# 11. User Submission List Shortcode

Implement:

```text
[didar_submissions]
```

This displays submissions belonging to the currently authenticated user.

Also support:

```text
[didar_submissions type="consultation"]
```

When `type` exists:

```text
show current user's submissions
AND
form_type = requested valid type
```

When `type` is missing or empty:

```text
show all submissions belonging to current user
```

The query itself should enforce ownership.

Do NOT retrieve every submission and hide other users' submissions afterward.

---

# 12. Submission List

The frontend submission list should at minimum be able to show:

```text
Submission ID
Form Type
Submission Date
Status
```

Provide a clean architecture that can later support:

```text
View Details
Edit
Delete
Pagination
Status filtering
```

Do not implement additional user actions unless required by the current task.

Paginate results.

Never load an unlimited number of submissions.

---

# 13. IDOR Protection

Submission IDs are untrusted input.

Whenever a frontend request references a submission:

1. Validate the ID.
2. Load the post.
3. Confirm post type is `didar_submission`.
4. Confirm current user owns the submission.
5. Only then expose its data.

Never implement logic equivalent to:

```php
get_post( $_GET['id'] );
```

and immediately display the result.

Knowing another submission ID must not grant access to it.

---

# 14. WordPress Admin

Administrators must be able to create a submission manually.

The workflow must be:

```text
Add Submission
      ↓
Select Form Type
      ↓
Load Fields
      ↓
Complete Fields
      ↓
Validate
      ↓
Save
```

The administrator should NOT see every possible field from every Form Type simultaneously.

---

# 15. Admin Form Type Selection

When creating a new submission, first display a Form Type selector.

Example:

```text
نوع فرم:
[ درخواست وقت سفارت ▼ ]
```

After selecting it, render fields belonging to that type.

Dynamic loading can use AJAX.

However:

**JavaScript must never determine what fields are allowed.**

PHP must retrieve the selected Form Type from the registry and return/render its legitimate fields.

---

# 16. Editing Existing Submissions

When an administrator edits an existing submission:

1. Read `_didar_form_type`.
2. Load its Form Definition.
3. Render the corresponding fields.
4. Populate saved values.
5. Validate changes.
6. Save only valid fields belonging to that Form Type.

Do not accidentally delete valid existing data because another Form Type was temporarily selected in the browser.

Changing the Form Type of an existing submission should be treated carefully.

Do not automatically migrate incompatible fields without explicit requirements.

---

# 17. Field Types

Build reusable support for field types required by `FORMS.txt`.

Expected types include at least concepts equivalent to:

```text
text
textarea
email
number
radio
select
checkbox / multi-select
date
time
list/repeater
file
hidden
honeypot
```

Use the exact requirements from `FORMS.txt`.

Do not force numeric-looking identifiers such as:

```text
phone number
passport number
national identifier
postal code
```

into mathematical behavior merely because they contain digits.

Choose storage/validation according to semantics, not appearance.

For example, values that may contain:

```text
leading zeroes
+
spaces
letters
```

should normally remain strings.

---

# 18. Repeater / Array Fields

Some fields may contain arrays or lists.

For example, the consultation specification contains a time field represented as an array.

Other forms contain companions/travelers or multi-selection fields.

Support arrays intentionally.

For array inputs:

1. verify the input is actually an array
2. unslash appropriately
3. sanitize each individual item
4. validate each value against its field definition
5. never pass an entire arbitrary nested request directly into post meta

---

# 19. Large Reference Lists

`FORMS.txt` contains shared reference data including:

* countries
* occupations
* academic levels
* invitation types
* employment types
* document types

Centralize reusable lists.

Do not duplicate a 200-item occupation list in multiple templates/classes.

Prefer structures such as:

```text
Form Registry
Reference Data
Renderer
```

or equivalent clean architecture.

---

# 20. Country List

The visa form specification contains a country selection list.

Treat this list as project reference data.

Where the same country list is used by multiple fields, define it once and reference it.

Do not independently duplicate country arrays.

---

# 21. Occupation List

The specification contains approximately 200 occupation entries with English/Persian labels.

Store the occupation dataset centrally.

Where practical, maintain stable internal values.

The same occupation dataset should be reusable by:

```text
Frontend
Admin
Validation
Display
```

Do not generate arbitrary occupations not contained in the specification.

---

# 22. File Fields

Some Didar fields are file uploads.

Frontend file uploads must use AJAX.

Use the dedicated `Didar_File_Service` and Didar file-record table for new uploads.

New Didar documents must not create WordPress Attachment posts or appear in the Media Library. Store stable Didar file IDs in submission metadata; filesystem paths remain server-controlled file-record data and must never be accepted from the browser.

The physical storage root is derived from `wp_upload_dir()` and uses the `didar-private` subdirectory. Do not hardcode an absolute upload path.

---

# 23. AJAX Upload Security

AJAX upload handlers must perform ALL relevant checks server-side.

At minimum:

```text
Authentication
Nonce verification
Authorization
Field validation
Form Type validation
File size validation
Extension validation
MIME validation
Upload error validation
Ownership validation where applicable
```

Never trust:

```text
$_FILES['...']['type']
```

as the sole MIME validation mechanism.

Never trust the original filename.

Never allow arbitrary executable files.

Use an explicit allowlist for each file field or a safe plugin-wide default where the specification does not define one.

---

# 24. Temporary AJAX Uploads

A file may be uploaded before the final submission post exists.

Design this safely.

Temporary uploads must have a Didar file record before a submission exists. The record must include owner, form, field, creation time, and temporary state.

When temporary uploads are implemented:

* associate them with the current user
* prevent another user from claiming them
* validate Didar file-record ownership before assignment
* clean abandoned temporary uploads according to a safe cleanup strategy

Do not expose arbitrary file IDs as trusted values.

---

# 25. AJAX Responses

Use structured WordPress JSON responses.

Prefer:

```php
wp_send_json_success()
```

and:

```php
wp_send_json_error()
```

Do not return raw stack traces.

Do not expose:

```text
filesystem paths
SQL errors
PHP warnings
server configuration
private exception details
```

to frontend users.

---

# 26. Nonces

All state-changing requests require CSRF protection.

Use context-appropriate WordPress nonce functions.

Examples include:

```php
wp_create_nonce()
wp_nonce_field()
wp_verify_nonce()
check_ajax_referer()
check_admin_referer()
```

Use specific nonce action names prefixed with `didar`.

Example concept:

```text
didar_submit_form
didar_upload_file
didar_admin_save_submission
```

A valid nonce is NOT authorization.

---

# 27. Authorization

Authorization must be checked independently.

Use WordPress capabilities:

```php
current_user_can()
```

For object-specific actions, use the relevant object/post capability where appropriate.

Do not authorize administrative operations by checking only:

```text
user role name
hidden input
nonce
admin page URL
JavaScript
```

Use capabilities.

---

# 28. Frontend Authentication

The frontend user panel functionality is intended for authenticated WordPress users.

If a visitor is not authenticated, do not create a submission belonging to an arbitrary supplied user ID.

Return an appropriate login/authentication message.

Do not leak submission information before authentication.

---

# 29. Input Processing

Treat every request value as untrusted.

The general server-side pipeline is:

```text
Request
↓
Unslash
↓
Validate structure
↓
Validate against Form Definition
↓
Sanitize
↓
Business validation
↓
Store
```

Never save:

```php
$_POST
```

or:

```php
$_REQUEST
```

directly.

---

# 30. Sanitization

Use field-appropriate WordPress sanitization.

Examples:

```php
sanitize_text_field()
sanitize_textarea_field()
sanitize_email()
absint()
esc_url_raw()
```

but select the function based on field semantics.

Arrays must be sanitized recursively/item-by-item according to their declared field type.

Do not assume sanitization means validation succeeded.

---

# 31. Allowlist Validation

Fields with predefined options MUST be checked against the registered options.

For example, if a radio field allows:

```text
hosori
telfoni
```

then:

```text
anything_else
```

must be rejected even if `sanitize_text_field()` would accept it.

The same rule applies to:

```text
Form Types
Countries
Statuses
Gender
Marital Status
Consultation Types
Invitation Types
Employment Types
Academic Levels
Multi-select values
```

when those values have predefined options.

---

# 32. Required Fields

Required fields must be enforced server-side.

HTML:

```html
required
```

and JavaScript validation are only UX improvements.

They are not security or authoritative validation.

A manually crafted HTTP request must not bypass required-field validation.

---

# 33. Output Escaping

Escape at output time.

Use context-specific WordPress escaping functions such as:

```php
esc_html()
esc_attr()
esc_url()
wp_kses()
```

Do not print raw stored user data into HTML.

This applies to both:

```text
Frontend
WordPress Admin
```

---

# 34. Honeypot

The embassy appointment specification includes a honeypot anti-spam field.

Implement honeypot behavior without displaying it as a normal user field.

If populated unexpectedly, reject or safely flag the submission according to implementation requirements.

Do not rely on honeypot protection as the only security mechanism.

---

# 35. Database Access

Prefer WordPress APIs:

```php
wp_insert_post()
wp_update_post()
get_post()
get_post_meta()
update_post_meta()
delete_post_meta()
WP_Query
```

Avoid direct SQL unless genuinely required.

If custom SQL is unavoidable, use:

```php
$wpdb->prepare()
```

correctly.

Never concatenate user-controlled input into SQL.

---

# 36. Suggested Plugin Structure

Prefer a modular architecture similar to:

```text
didar/
│
├── didar.php
│
├── AGENTS.md
├── FORMS.txt
│
├── includes/
│   ├── class-didar-plugin.php
│   ├── class-didar-post-type.php
│   ├── class-didar-form-registry.php
│   ├── class-didar-reference-data.php
│   ├── class-didar-field-renderer.php
│   ├── class-didar-validator.php
│   ├── class-didar-submission-service.php
│   ├── class-didar-shortcodes.php
│   ├── class-didar-ajax.php
│   └── class-didar-admin.php
│
├── templates/
│   ├── form.php
│   ├── submissions.php
│   └── submission-details.php
│
├── assets/
│   ├── css/
│   │   ├── frontend.css
│   │   └── admin.css
│   │
│   └── js/
│       ├── frontend.js
│       └── admin.js
│
└── languages/
```

This structure is a recommendation.

Adapt it if the existing repository already has a coherent architecture.

Do not rewrite a working architecture merely to match this tree.

---

# 37. PHP Naming

Avoid global namespace collisions.

Prefix procedural identifiers with:

```text
didar_
```

Prefer classes/namespaces where appropriate.

Examples:

```text
Didar_Plugin
Didar_Form_Registry
Didar_Submission_Service
Didar_Validator
```

Do not create generic global functions such as:

```php
save_form()
upload_file()
validate()
```

---

# 38. Meta Keys

Prefix private post meta keys.

Example:

```text
_didar_form_type
_didar_status
_didar_fields
```

Individual field storage or structured storage may be chosen based on query requirements.

Before choosing one large serialized metadata object versus individual meta keys, consider:

```text
Admin filtering
Searching
Reporting
Query performance
Future compatibility
```

Do not optimize prematurely.

---

# 39. Form Renderer

Implement reusable rendering.

Conceptually:

```php
$renderer->render_field( $field_definition, $value );
```

The renderer should understand field types rather than individual Form Types.

Avoid code such as:

```php
if ( $form_type === 'visa_request' ) {
    // 500 lines of HTML
}
```

followed by another large block for every form.

---

# 40. Validator

Validation must also be definition-driven.

Conceptually:

```php
$validator->validate(
    $form_definition,
    $submitted_data
);
```

Validation should return structured errors associated with field names.

Example concept:

```php
[
    'mobile' => 'شماره موبایل معتبر نیست.',
]
```

Do not mix HTML rendering deeply into validation code.

---

# 41. Admin Security

When saving submissions from WordPress Admin:

Check:

```text
nonce
capability
autosave state
revision state where relevant
valid post type
valid Form Type
valid fields
```

Do not blindly save metadata on every `save_post` call.

Avoid recursion problems when calling functions that themselves trigger post-save hooks.

---

# 42. Frontend Assets

Do not load Didar CSS and JavaScript globally on every WordPress page unless required.

Prefer loading assets only when Didar functionality is present.

Pass AJAX configuration from PHP to JavaScript safely.

Do not hardcode deployment-specific WordPress URLs in JavaScript.

---

# 43. Internationalization

Make plugin UI strings translation-ready.

Use WordPress internationalization functions such as:

```php
__()
_e()
esc_html__()
esc_attr__()
```

where appropriate.

Use:

```text
didar
```

as the text domain.

The business labels supplied in `FORMS.txt` may remain Persian while still being passed through translation functions where appropriate.

---

# 44. Dates

The specification contains different displayed date formats.

Do not assume display format equals storage format.

Prefer normalized internal date storage where practical and format it for display.

Do not silently reinterpret ambiguous dates.

Preserve the requirements specified for each Form Type.

---

# 45. Sensitive User Data

These forms contain personal information such as:

```text
names
phone numbers
email addresses
passport information
addresses
financial information
travel history
employment information
uploaded documents
```

Treat submission data as private.

Do not expose submissions through public REST responses, public archives, feeds, search, or frontend queries unless explicitly required.

The CPT should not accidentally create publicly browsable submission pages.

Use conservative CPT visibility settings.

---

# 46. REST API

Do not expose submission metadata through the WordPress REST API by default.

If REST support becomes necessary later:

* require authentication
* perform capability/ownership checks
* explicitly define exposed fields
* never expose arbitrary post meta

---

# 47. Privacy by Design

Collect and store only fields required by the provided Form Definitions.

Do not add hidden tracking fields or unnecessary personal data.

Do not log complete submission payloads to production logs.

Never log uploaded document contents.

---

# 48. Admin Listing

Provide a useful WordPress Admin list for Didar submissions.

Where practical include columns such as:

```text
Submission ID
Form Type
User
Status
Date
```

Do not dump every form field into the main CPT list table.

Large forms such as traveler evaluation and visa request contain too many fields for that.

---

# 49. Admin Filtering

Design the CPT so admin filtering can later support:

```text
Form Type
Status
User
Date
```

At minimum, Form Type should be easy to identify.

Do not implement expensive unbounded queries.

---

# 50. Form-Specific Requirements

Read the exact requirements from `FORMS.txt`.

Important examples include:

## Consultation

Contains fields such as:

```text
نام و نام خانوادگی
شماره همراه
وضعیت تاهل
موضوع مشاوره
نوع مشاوره
تاریخ مشاوره
زمان مشاوره
```

Preserve explicitly provided option mappings.

## Embassy Appointment

Contains:

```text
request ownership
country
service type
profession
date
appointment urgency
personal details
passport information
gender
birth information
```

It also contains administrative status information and honeypot behavior.

## Traveler Evaluation

This is a large multi-section form.

Its sections include concepts such as:

```text
identity
passport
family/address
employment
travel purpose
Schengen history
invitation/accommodation
travel funding
financial information
```

Preserve logical sections in rendering rather than presenting one undifferentiated list of fields.

## Complaint / Suggestion

Contains fields such as:

```text
date
first name
last name
mobile
subject
message
```

## Visa Request

Contains multiple sections including:

```text
identity/contact
education
invitation
travel documents
financial/assets
employment
visa/travel history
companions
```

Use the supplied country and occupation reference lists where applicable.

---

# 51. Multi-Section Forms

Large forms should support sections.

A Form Definition may therefore contain:

```text
sections
    → fields
```

instead of one flat field list.

Example concept:

```php
[
    'sections' => [
        'identity' => [
            'label'  => 'اطلاعات هویتی',
            'fields' => [],
        ],
        'passport' => [
            'label'  => 'اطلاعات پاسپورت',
            'fields' => [],
        ],
    ],
]
```

The registry remains the source of truth regardless of internal representation.

---

# 52. Conditional Fields

Some business fields may logically depend on another answer.

Do not delete conditional data solely through JavaScript.

If conditional UI is implemented:

```text
JavaScript → controls visibility
PHP → determines validity
```

Server-side logic remains authoritative.

---

# 53. Accessibility

Generated forms should use proper:

```text
<label>
<input>
<select>
<textarea>
fieldset
legend
```

semantics where appropriate.

Associate validation errors with fields.

Do not rely only on color to communicate errors.

---

# 54. Frontend Styling

Keep frontend styling scoped to Didar.

Use classes prefixed with:

```text
didar-
```

Avoid broad CSS selectors such as:

```css
input {}
button {}
form {}
```

that could break the active theme.

---

# 55. JavaScript Scope

Avoid unnecessary global JavaScript variables.

Prefix/namescope Didar behavior.

Do not assume jQuery unless the implementation intentionally registers it as a dependency.

Prefer maintainable, minimal JavaScript.

---

# 56. AJAX Actions

Prefix AJAX action names.

Examples:

```text
didar_upload_file
didar_get_form_fields
didar_submit_form
```

Do not use generic actions such as:

```text
upload
save
submit
```

---

# 57. Error Handling

Provide understandable user-facing errors.

Examples:

```text
فیلد الزامی تکمیل نشده است.
نوع فرم نامعتبر است.
فایل انتخاب‌شده مجاز نیست.
حجم فایل بیش از حد مجاز است.
نشست شما منقضی شده است؛ صفحه را تازه‌سازی کنید.
امکان ثبت درخواست وجود نداشت.
```

Do not expose implementation internals.

---

# 58. Success Handling

After a successful frontend submission, return enough information to identify the result safely.

For example:

```text
success
submission_id
message
```

Do not return private post metadata unnecessarily.

Prevent accidental duplicate submissions caused by repeated clicks where practical.

Disable/re-enable submission UI appropriately during AJAX operations.

---

# 59. Never Trust the Browser

Assume an attacker can modify:

```text
Form Type
Field names
Field values
User ID
Submission ID
Attachment ID
Status
Select values
Required fields
Hidden fields
AJAX action
MIME type
Filename
```

Every security-sensitive decision must be repeated server-side.

---

# 60. WordPress Native APIs

Prefer WordPress APIs instead of reimplementing platform functionality.

Examples:

```text
Custom Post Types
Post Meta
WP_Query
Media Library
Nonce API
Capabilities API
Shortcode API
AJAX API
Internationalization API
Filesystem/upload APIs
```

Do not directly manipulate WordPress core tables when a supported API exists.

---

# 61. No Theme Dependency

Didar must be a standalone plugin.

Do not place required business logic inside:

```text
functions.php
theme templates
theme JavaScript
theme-specific hooks
```

unless explicitly requested.

Changing the active theme should not destroy Didar functionality.

---

# 62. No Core Modification

Never modify:

```text
wp-admin
wp-includes
WordPress core files
```

All functionality must live within the plugin.

---

# 63. Development Workflow

Before writing code for a task:

1. Read this `AGENTS.md`.
2. Read `FORMS.txt` when the task involves forms.
3. Inspect the repository.
4. Identify existing architecture.
5. Search for existing Didar functionality.
6. Understand how data is currently stored.
7. Identify security implications.
8. Create the smallest coherent implementation.
9. Reuse existing code where sensible.
10. Test the affected behavior.

Do not start by blindly generating a new plugin architecture if the repository already contains implementation.

---

# 64. Before Modifying Existing Code

Inspect related:

```text
PHP classes
hooks
shortcodes
AJAX handlers
JavaScript
templates
CPT registration
meta storage
```

before making changes.

Do not duplicate functionality that already exists.

---

# 65. Implementation Order

For a new implementation, prefer this order:

```text
1. Plugin bootstrap
2. CPT
3. Form Registry
4. Reference Data
5. Field Renderer
6. Validator
7. Submission Service
8. Admin UI
9. Frontend form shortcode
10. Submission-list shortcode
11. AJAX/file upload
12. Security review
13. Styling
14. Testing
```

Do not attempt to build every large form before the underlying registry/validation architecture works.

---

# 66. Incremental Development

Implement one vertical slice first.

A good first target is:

```text
consultation
```

Verify that it works through:

```text
Registry
→ Frontend Rendering
→ Validation
→ Saving
→ Admin Editing
→ User Submission List
```

before expanding the same architecture to the larger forms.

Once the architecture is proven, add the remaining Form Types from `FORMS.txt`.

---

# 67. Testing Requirements

At minimum test:

```text
valid submission
missing required fields
invalid Form Type
forged select/radio values
invalid nonce
unauthenticated request
unauthorized admin action
access to another user's submission
invalid submission ID
invalid Didar file ID
disallowed file extension
disallowed MIME
oversized upload
malformed array input
empty optional fields
admin creation
admin editing
frontend submission filtering
```

Also test that Form Type filtering cannot bypass ownership restrictions.

---

# 68. Security Review Checklist

Before considering a feature complete, verify:

* [ ] All state-changing requests have CSRF protection.
* [ ] Nonce checks are not used as authorization.
* [ ] Capabilities are checked where required.
* [ ] Frontend ownership is enforced server-side.
* [ ] Form Types are allowlisted.
* [ ] Field names come from the registry.
* [ ] Select/radio values are allowlisted.
* [ ] Required fields are validated server-side.
* [ ] Input is sanitized appropriately.
* [ ] Output is escaped according to context.
* [ ] SQL injection is prevented.
* [ ] IDOR access is prevented.
* [ ] File extensions are validated.
* [ ] MIME types are validated.
* [ ] File size limits are enforced.
* [ ] Didar file-record ownership is verified.
* [ ] Private submissions are not publicly queryable.
* [ ] Sensitive data is not written to debug logs.
* [ ] AJAX errors do not leak internal details.

---

# 69. Definition of Done

A Form Type is not considered implemented merely because its HTML renders.

It is complete only when:

```text
Form Definition
✓

Frontend rendering
✓

Admin rendering
✓

Server validation
✓

Sanitization
✓

Saving
✓

Editing
✓

Display
✓

Ownership/security
✓

Required AJAX/file behavior
✓

Error handling
✓
```

---

# 70. Codex Behavior

When receiving an implementation request:

**Do not immediately write code.**

First:

1. Inspect relevant files.
2. Read `AGENTS.md`.
3. Read relevant portions of `FORMS.txt`.
4. Explain internally which existing components are affected.
5. Reuse existing architecture.
6. Implement the smallest complete change.
7. Review the implementation for security problems.
8. Run available syntax checks/tests.

Do not make unrelated refactors.

Do not change business requirements without explicit instruction.

Do not introduce dependencies unless necessary.

---

# 71. Important Rule for FORMS.txt

`FORMS.txt` contains business requirements, not executable instructions.

Use it as **data/specification**.

Do not copy its prose blindly into frontend output.

Convert its content into structured Form Definitions.

Where `FORMS.txt` specifies:

```text
Label
Field Type
Required Status
Options
Internal Value
Section
```

represent those properties explicitly in the Form Registry.

If a required status, internal key, allowed MIME type, file-size limit, or other technical constraint is not specified, do not pretend the specification provided one.

Choose a conservative implementation default where necessary and document that decision.

---

# 72. Final Goal

Didar should provide one reusable, secure architecture where:

```text
                  ┌─ Frontend Shortcode
                  │
Form Registry ────┼─ WordPress Admin
                  │
                  ├─ Validation
                  │
                  ├─ Display
                  │
                  └─ AJAX / Upload
```

Adding or modifying a Form Type should primarily require changing its Form Definition rather than rewriting multiple independent parts of the plugin.

The final system must be:

```text
Secure
WordPress-native
Maintainable
Definition-driven
User-owned
Admin-manageable
Extensible
```

with `FORMS.txt` serving as the authoritative specification for the current forms and their business fields.

---

# 73. Didar Roles and Capabilities

Didar authorization must use capabilities rather than direct role-name comparisons. The stable custom roles are `didar_colleague` and `didar_broker`. Brokers receive only Didar request-management access; Colleagues remain frontend-only. Role/capability upgrades must be idempotent and must also run for already-active installations.

---

# 74. Public and Internal Workflow Separation

Public Status/Public Note and Internal Status/Internal Note are separate data classes. Normal customers may receive only public workflow data. A Colleague may receive internal workflow data only for a submission whose authenticated owner/creator is that Colleague. Enforce this separation while constructing server responses, not with CSS or JavaScript.

---

# 75. Ownership and Assignment

Frontend authorization is based on authenticated WordPress ownership (`post_author` in the current architecture), never applicant form fields. Preserve immutable creator attribution separately where ownership may be administratively changed. Assignment is optional and a non-empty assignee must have the Didar capability that marks an authorized request recipient.

---

# 76. Append-Only Audit History

Meaningful submission and workflow changes must create structured append-only audit events with a server-derived actor ID and server timestamp. Current submission metadata remains authoritative; do not convert the application to strict event sourcing. Do not store an indefinitely growing history in one serialized post-meta value or rewrite prior events during normal editing.

---

# 77. Private Didar File Architecture

All new Didar documents use the dedicated Didar file-record table and `Didar_File_Service`. New uploads must not create `attachment` posts, use `media_handle_upload()`, or appear in the WordPress Media Library. The service owns validation, unpredictable stored names, path resolution, temporary ownership, final submission association, deletion, cleanup, and download URL generation.

Submission form metadata stores stable Didar file IDs only. Original filenames are display metadata and never filesystem identities. Physical paths are always reconstructed from server-controlled relative paths under the upload directory and must be checked against traversal and absolute-path input.

Legacy Media Library uploads are not migrated or bulk-deleted by this architecture.

---

# 78. File Download Modes

The file-download mode is resolved centrally from Didar Settings. Only `secure` and `direct` are valid; absent, empty, or invalid values resolve to `secure`.

Secure links use the authenticated Didar download controller. The controller must load the file record and associated submission, verify that the file ID is still stored under the recorded field, reuse request-view authorization, resolve a server-controlled path, and only then stream the file. A nonce may supplement but never replace authorization.

Direct mode returns the physical URL for the same stored file and intentionally bypasses Didar authorization at download time. The Settings UI and documentation must disclose this risk. Switching modes must not copy files or rewrite submission metadata.

Apache/IIS protection files may block direct access in Secure mode. Because this is not portable to every server, Nginx and other deployments that ignore `.htaccess`/`web.config` require an equivalent server rule for `didar-private`; never claim portable server-level privacy without that qualification.

---

# 79. File Deletion and Cleanup

Download and delete permissions are separate. Final-file deletion requires request edit authorization plus a verified submission/field/file relationship. Temporary-file deletion requires the authenticated owner and exact temporary context. Final document changes create append-only file events using Didar file IDs; abandoned temporary records/files are removed by the bounded scheduled cleanup and must not create permanent request-history events.
