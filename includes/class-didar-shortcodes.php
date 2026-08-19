<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Didar_Shortcodes {
	const PAGE_SETTINGS_OPTION = 'didar_submission_pages';

	private $registry;
	private $renderer;
	private $validator;
	private $service;
	private $assets_loaded = false;
	private $edit_state = array();

	public function __construct( Didar_Form_Registry $registry, Didar_Field_Renderer $renderer, Didar_Validator $validator, Didar_Submission_Service $service ) {
		$this->registry  = $registry;
		$this->renderer  = $renderer;
		$this->validator = $validator;
		$this->service   = $service;

		add_shortcode( 'didar_form', array( $this, 'form_shortcode' ) );
		add_shortcode( 'didar_submissions', array( $this, 'submissions_shortcode' ) );
		add_shortcode( 'didar_submission_details', array( $this, 'submission_details_shortcode' ) );
		add_shortcode( 'didar_submission_edit', array( $this, 'submission_edit_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_assets' ) );
		add_action( 'template_redirect', array( $this, 'handle_edit_submission' ) );
	}

	public function maybe_enqueue_assets() {
		global $post;
		$shortcodes = array( 'didar_form', 'didar_submissions', 'didar_submission_details', 'didar_submission_edit' );
		if ( $post instanceof WP_Post && array_filter( $shortcodes, function ( $shortcode ) use ( $post ) { return has_shortcode( $post->post_content, $shortcode ); } ) ) {
			$this->enqueue_assets();
		}
	}

	public function form_shortcode( $atts ) {
		$atts = shortcode_atts( array( 'type' => '' ), $atts, 'didar_form' );
		$type = sanitize_key( $atts['type'] );
		$form = $this->registry->get( $type );
		$this->enqueue_assets();

		if ( ! is_user_logged_in() ) {
			return $this->notice( __( 'برای ثبت درخواست ابتدا وارد حساب کاربری خود شوید.', 'didar' ), 'warning' );
		}
		if ( ! $form ) {
			return $this->notice( __( 'نوع فرم نامعتبر است.', 'didar' ), 'error' );
		}

		$values        = array();
		$errors        = array();
		$submission_id = 0;
		$shared_note   = '';

		if ( isset( $_POST['didar_action'], $_POST['didar_form_type'] ) && 'submit_form' === sanitize_key( wp_unslash( $_POST['didar_action'] ) ) && $type === sanitize_key( wp_unslash( $_POST['didar_form_type'] ) ) ) {
			$nonce = isset( $_POST['didar_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['didar_nonce'] ) ) : '';
			if ( ! wp_verify_nonce( $nonce, 'didar_submit_form_' . $type ) ) {
				$errors['_form'] = __( 'نشست شما منقضی شده است؛ صفحه را تازه‌سازی کنید.', 'didar' );
			} else {
				if ( isset( $_POST['didar_shared_note'] ) && is_array( $_POST['didar_shared_note'] ) ) {
					$errors['shared_note'] = __( 'ساختار یادداشت معتبر نیست.', 'didar' );
				} else {
					$shared_note = isset( $_POST['didar_shared_note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['didar_shared_note'] ) ) : '';
				}
				$token  = isset( $_POST['didar_request_token'] ) ? sanitize_key( wp_unslash( $_POST['didar_request_token'] ) ) : '';
				$key    = 'didar_submit_' . get_current_user_id() . '_' . $token;
				$cached = $token ? absint( get_transient( $key ) ) : 0;
				if ( $cached && Didar_Post_Type::POST_TYPE === get_post_type( $cached ) ) {
					$submission_id = $cached;
				} else {
					$raw    = isset( $_POST['didar_fields'] ) && is_array( $_POST['didar_fields'] ) ? wp_unslash( $_POST['didar_fields'] ) : array();
					$result = $this->validator->validate( $type, $raw, 'frontend' );
					$values = array_intersect_key( $raw, $this->registry->fields( $type ) );
					$errors = array_merge( $errors, $result['errors'] );
					if ( $result['valid'] && empty( $errors['shared_note'] ) ) {
						$created = $this->service->create( $type, $result['data'], get_current_user_id(), $shared_note );
						if ( is_wp_error( $created ) ) {
							$errors['_form'] = $created->get_error_message();
						} else {
							$submission_id = $created;
							if ( $token ) {
								set_transient( $key, $submission_id, HOUR_IN_SECONDS );
							}
						}
					}
				}
			}
		}

		ob_start();
		echo '<div class="didar-app" dir="rtl">';
		if ( $submission_id ) {
			echo '<div class="didar-notice didar-notice--success" role="status">';
			echo '<strong>' . esc_html__( 'درخواست شما با موفقیت ثبت شد.', 'didar' ) . '</strong> ';
			echo esc_html( sprintf( __( 'شماره پیگیری: %d', 'didar' ), $submission_id ) );
			echo '</div></div>';
			return ob_get_clean();
		}

		echo '<form class="didar-form" method="post" data-didar-form data-form-type="' . esc_attr( $type ) . '" novalidate>';
		echo '<header class="didar-form-header"><p class="didar-eyebrow">' . esc_html__( 'فرم آنلاین دیدار', 'didar' ) . '</p><h2>' . esc_html( $form['label'] ) . '</h2>';
		if ( ! empty( $form['description'] ) ) {
			echo '<p>' . esc_html( $form['description'] ) . '</p>';
		}
		echo '</header>';

		if ( $errors ) {
			$this->render_error_summary( $errors, $type );
		}

		wp_nonce_field( 'didar_submit_form_' . $type, 'didar_nonce' );
		echo '<input type="hidden" name="didar_action" value="submit_form">';
		echo '<input type="hidden" name="didar_form_type" value="' . esc_attr( $type ) . '">';
		echo '<input type="hidden" name="didar_request_token" value="' . esc_attr( wp_generate_uuid4() ) . '">';
		$this->renderer->render_sections( $form, $values, $errors, 'frontend' );
		$this->render_shared_note_field( $shared_note, isset( $errors['shared_note'] ) ? $errors['shared_note'] : '' );
		echo '<div class="didar-actions"><button type="submit" class="didar-submit"><span>' . esc_html( $form['submit_label'] ) . '</span><span class="didar-spinner" aria-hidden="true"></span></button><p class="didar-required-note">' . esc_html__( 'فیلدهای ستاره‌دار الزامی هستند.', 'didar' ) . '</p></div>';
		echo '</form></div>';

		return ob_get_clean();
	}

	public function submissions_shortcode( $atts ) {
		if ( ! is_user_logged_in() ) {
			return $this->notice( __( 'برای مشاهده درخواست‌ها ابتدا وارد حساب کاربری خود شوید.', 'didar' ), 'warning' );
		}
		$atts = shortcode_atts( array( 'type' => '' ), $atts, 'didar_submissions' );
		$type = sanitize_key( $atts['type'] );
		if ( $type && ! $this->registry->is_valid_type( $type ) ) {
			return $this->notice( __( 'نوع فرم نامعتبر است.', 'didar' ), 'error' );
		}

		$this->enqueue_assets();
		$page = isset( $_GET['didar_page'] ) ? max( 1, absint( $_GET['didar_page'] ) ) : 1;
		$list_url = get_permalink( get_queried_object_id() );
		if ( $page > 1 && $list_url ) {
			$list_url = add_query_arg( 'didar_page', $page, $list_url );
		}
		$args = array(
			'post_type'           => Didar_Post_Type::POST_TYPE,
			'post_status'         => 'publish',
			'author'              => get_current_user_id(),
			'posts_per_page'      => 10,
			'paged'               => $page,
			'orderby'             => 'date',
			'order'               => 'DESC',
			'ignore_sticky_posts' => true,
			'no_found_rows'       => false,
		);
		if ( $type ) {
			$args['meta_query'] = array(
				array( 'key' => '_didar_form_type', 'value' => $type, 'compare' => '=' ),
			);
		}
		$query = new WP_Query( $args );

		ob_start();
		echo '<div class="didar-app didar-submissions" dir="rtl"><div class="didar-list-header"><div><p class="didar-eyebrow">' . esc_html__( 'پنل کاربری', 'didar' ) . '</p><h2>' . esc_html__( 'درخواست‌های من', 'didar' ) . '</h2></div><span class="didar-count">' . esc_html( sprintf( __( '%d درخواست', 'didar' ), $query->found_posts ) ) . '</span></div>';
		if ( ! $query->have_posts() ) {
			echo '<div class="didar-empty"><strong>' . esc_html__( 'هنوز درخواستی ثبت نکرده‌اید.', 'didar' ) . '</strong><p>' . esc_html__( 'درخواست‌های ثبت‌شده شما در این بخش نمایش داده می‌شوند.', 'didar' ) . '</p></div>';
		} else {
			echo '<div class="didar-table-wrap"><table class="didar-table"><thead><tr><th scope="col">' . esc_html__( 'شماره', 'didar' ) . '</th><th scope="col">' . esc_html__( 'نوع فرم', 'didar' ) . '</th><th scope="col">' . esc_html__( 'تاریخ ثبت', 'didar' ) . '</th><th scope="col">' . esc_html__( 'وضعیت', 'didar' ) . '</th><th scope="col">' . esc_html__( 'عملیات', 'didar' ) . '</th></tr></thead><tbody>';
			while ( $query->have_posts() ) {
				$query->the_post();
				$post_id   = get_the_ID();
				$form_type = get_post_meta( $post_id, '_didar_form_type', true );
				$form       = $this->registry->get( $form_type );
				$status     = get_post_meta( $post_id, '_didar_status', true );
				$details_url = $this->get_submission_page_url( 'details_page_id', $post_id, $list_url );
				$action      = $details_url
					? '<a class="didar-button didar-button--secondary" href="' . esc_url( $details_url ) . '">' . esc_html__( 'مشاهده جزئیات', 'didar' ) . '</a>'
					: '<span class="didar-action-unavailable">' . esc_html__( 'صفحه جزئیات تنظیم نشده است.', 'didar' ) . '</span>';
				echo '<tr><td data-label="' . esc_attr__( 'شماره', 'didar' ) . '"><strong>#' . esc_html( $post_id ) . '</strong></td><td data-label="' . esc_attr__( 'نوع فرم', 'didar' ) . '">' . esc_html( $form ? $form['label'] : $form_type ) . '</td><td data-label="' . esc_attr__( 'تاریخ ثبت', 'didar' ) . '"><time datetime="' . esc_attr( get_the_date( DATE_W3C ) ) . '">' . esc_html( get_the_date() ) . '</time></td><td data-label="' . esc_attr__( 'وضعیت', 'didar' ) . '"><span class="didar-status didar-status--' . esc_attr( sanitize_html_class( $status ) ) . '">' . esc_html( $this->service->get_status_label( $status ) ) . '</span></td><td data-label="' . esc_attr__( 'عملیات', 'didar' ) . '">' . wp_kses_post( $action ) . '</td></tr>';
			}
			echo '</tbody></table></div>';
			if ( $query->max_num_pages > 1 ) {
				echo '<nav class="didar-pagination" aria-label="' . esc_attr__( 'صفحه‌بندی درخواست‌ها', 'didar' ) . '">';
				echo wp_kses_post( paginate_links( array( 'base' => esc_url_raw( add_query_arg( 'didar_page', '%#%' ) ), 'format' => '', 'current' => $page, 'total' => $query->max_num_pages, 'prev_text' => __( 'قبلی', 'didar' ), 'next_text' => __( 'بعدی', 'didar' ) ) ) );
				echo '</nav>';
			}
		}
		wp_reset_postdata();
		echo '</div>';
		return ob_get_clean();
	}

	public function submission_details_shortcode() {
		if ( ! is_user_logged_in() ) {
			return $this->notice( __( 'برای مشاهده درخواست ابتدا وارد حساب کاربری خود شوید.', 'didar' ), 'warning' );
		}

		$this->enqueue_assets();
		$submission_id = $this->requested_submission_id();
		if ( ! $submission_id ) {
			return $this->notice( __( 'درخواست معتبر نیست.', 'didar' ), 'error' );
		}

		return $this->render_submission_details( $submission_id );
	}

	public function submission_edit_shortcode() {
		if ( ! is_user_logged_in() ) {
			return $this->notice( __( 'برای ویرایش درخواست ابتدا وارد حساب کاربری خود شوید.', 'didar' ), 'warning' );
		}

		$this->enqueue_assets();
		$submission_id = $this->requested_submission_id();
		if ( ! $submission_id ) {
			return $this->notice( __( 'درخواست معتبر نیست.', 'didar' ), 'error' );
		}

		return $this->render_submission_edit( $submission_id );
	}

	public function handle_edit_submission() {
		$action = isset( $_POST['didar_action'] ) && ! is_array( $_POST['didar_action'] ) ? sanitize_key( wp_unslash( $_POST['didar_action'] ) ) : '';
		if ( 'update_submission' !== $action || ! is_user_logged_in() ) {
			return;
		}

		$settings     = $this->get_page_settings();
		$edit_page_id = isset( $settings['edit_page_id'] ) ? absint( $settings['edit_page_id'] ) : 0;
		if ( ! $edit_page_id || ! is_page( $edit_page_id ) ) {
			return;
		}

		$submission_id = $this->requested_submission_id();
		$posted_id     = isset( $_POST['didar_submission_id'] ) && ! is_array( $_POST['didar_submission_id'] ) ? absint( wp_unslash( $_POST['didar_submission_id'] ) ) : 0;
		if ( ! $submission_id ) {
			return;
		}

		$user_id     = get_current_user_id();
		$post        = $this->service->get_owned_submission( $submission_id, $user_id );
		$form_type   = $post ? get_post_meta( $submission_id, '_didar_form_type', true ) : '';
		$form        = $this->registry->get( $form_type );
		$values      = $post ? $this->service->get_fields( $submission_id ) : array();
		$shared_note = $post ? $this->service->get_shared_note( $submission_id ) : '';
		$errors      = array();
		$nonce       = isset( $_POST['didar_nonce'] ) && ! is_array( $_POST['didar_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['didar_nonce'] ) ) : '';
		$return_url  = $this->requested_return_url();
		$details_url = $this->get_submission_page_url( 'details_page_id', $submission_id, $return_url );

		if ( $posted_id !== $submission_id ) {
			$errors['_form'] = __( 'درخواست معتبر نیست.', 'didar' );
		} elseif ( ! $post || ! $form ) {
			$errors['_form'] = __( 'این درخواست یافت نشد یا در دسترس شما نیست.', 'didar' );
		} elseif ( ! wp_verify_nonce( $nonce, 'didar_edit_submission_' . $submission_id ) ) {
			$errors['_form'] = __( 'نشست شما منقضی شده است؛ صفحه را تازه‌سازی کنید.', 'didar' );
		} elseif ( ! $this->service->is_owner_editable( $submission_id, $user_id ) ) {
			$errors['_form'] = __( 'درخواست تکمیل‌شده دیگر قابل ویرایش نیست.', 'didar' );
		} elseif ( ! $details_url ) {
			$errors['_form'] = __( 'صفحه مشاهده جزئیات هنوز توسط مدیر تنظیم نشده است.', 'didar' );
		} else {
			$raw = isset( $_POST['didar_fields'] ) && is_array( $_POST['didar_fields'] ) ? wp_unslash( $_POST['didar_fields'] ) : array();
			if ( isset( $_POST['didar_shared_note'] ) && is_array( $_POST['didar_shared_note'] ) ) {
				$errors['shared_note'] = __( 'ساختار یادداشت معتبر نیست.', 'didar' );
			} else {
				$shared_note = isset( $_POST['didar_shared_note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['didar_shared_note'] ) ) : '';
			}

			$result = $this->validator->validate( $form_type, $raw, 'frontend', $submission_id );
			$values = array_intersect_key( $raw, $this->registry->fields( $form_type ) );
			$errors = array_merge( $errors, $result['errors'] );
			if ( $result['valid'] && empty( $errors['shared_note'] ) ) {
				$saved = $this->service->update_by_owner( $submission_id, $result['data'], $shared_note, $user_id );
				if ( is_wp_error( $saved ) ) {
					$errors['_form'] = $saved->get_error_message();
				} else {
					set_transient( 'didar_edit_success_' . $user_id . '_' . $submission_id, 1, MINUTE_IN_SECONDS );
					if ( wp_safe_redirect( $details_url ) ) {
						exit;
					}
					delete_transient( 'didar_edit_success_' . $user_id . '_' . $submission_id );
					$errors['_form'] = __( 'انتقال به صفحه جزئیات انجام نشد. درخواست ذخیره شده است.', 'didar' );
				}
			}
		}

		$this->edit_state[ $submission_id ] = array(
			'values'      => $values,
			'shared_note' => $shared_note,
			'errors'      => $errors,
		);
	}

	private function render_submission_details( $submission_id ) {
		$user_id = get_current_user_id();
		$post    = $this->service->get_owned_submission( $submission_id, $user_id );
		if ( ! $post ) {
			return $this->notice( __( 'این درخواست یافت نشد یا در دسترس شما نیست.', 'didar' ), 'error' );
		}

		$form_type = get_post_meta( $submission_id, '_didar_form_type', true );
		$form      = $this->registry->get( $form_type );
		if ( ! $form ) {
			return $this->notice( __( 'این درخواست یافت نشد یا در دسترس شما نیست.', 'didar' ), 'error' );
		}

		$values      = $this->service->get_fields( $submission_id );
		$shared_note = $this->service->get_shared_note( $submission_id );
		$status      = get_post_meta( $submission_id, '_didar_status', true );
		$editable    = $this->service->is_owner_editable( $submission_id, $user_id );
		$back_url    = $this->requested_return_url();
		$edit_url    = $this->get_submission_page_url( 'edit_page_id', $submission_id, $back_url );
		$success_key = 'didar_edit_success_' . $user_id . '_' . $submission_id;
		$updated     = (bool) get_transient( $success_key );
		delete_transient( $success_key );

		ob_start();
		echo '<div class="didar-app" dir="rtl"><article class="didar-submission-details">';
		echo '<header class="didar-list-header didar-details-header"><div><p class="didar-eyebrow">' . esc_html__( 'جزئیات درخواست', 'didar' ) . '</p><h2>' . esc_html( $form['label'] ) . ' <span class="didar-heading-id">#' . esc_html( $submission_id ) . '</span></h2></div><span class="didar-status didar-status--' . esc_attr( sanitize_html_class( $status ) ) . '">' . esc_html( $this->service->get_status_label( $status ) ) . '</span></header>';
		if ( $updated ) {
			echo '<div class="didar-notice didar-notice--success" role="status">' . esc_html__( 'تغییرات درخواست با موفقیت ذخیره شد.', 'didar' ) . '</div>';
		}
		echo '<div class="didar-details-meta"><span><strong>' . esc_html__( 'تاریخ ثبت:', 'didar' ) . '</strong> ' . esc_html( get_the_date( '', $submission_id ) ) . '</span><span><strong>' . esc_html__( 'آخرین ویرایش:', 'didar' ) . '</strong> ' . esc_html( get_the_modified_date( '', $submission_id ) ) . '</span></div>';
		$this->render_detail_sections( $form, $values );
		echo '<section class="didar-detail-section"><h3>' . esc_html__( 'یادداشت مشترک', 'didar' ) . '</h3><div class="didar-detail-note">' . ( '' !== $shared_note ? nl2br( esc_html( $shared_note ) ) : '—' ) . '</div></section>';
		if ( ! $editable ) {
			echo '<div class="didar-notice didar-notice--warning">' . esc_html__( 'این درخواست تکمیل شده است و دیگر قابل ویرایش نیست.', 'didar' ) . '</div>';
		}
		echo '<footer class="didar-detail-actions"><a class="didar-button didar-button--secondary" href="' . esc_url( $back_url ) . '">' . esc_html__( 'بازگشت به درخواست‌ها', 'didar' ) . '</a>';
		if ( $editable && $edit_url ) {
			echo '<a class="didar-button didar-button--primary" href="' . esc_url( $edit_url ) . '">' . esc_html__( 'ویرایش درخواست', 'didar' ) . '</a>';
		} elseif ( $editable ) {
			echo '<span class="didar-action-unavailable">' . esc_html__( 'صفحه ویرایش تنظیم نشده است.', 'didar' ) . '</span>';
		}
		echo '</footer></article></div>';

		return ob_get_clean();
	}

	private function render_submission_edit( $submission_id ) {
		$user_id = get_current_user_id();
		$post    = $this->service->get_owned_submission( $submission_id, $user_id );
		if ( ! $post ) {
			return $this->notice( __( 'این درخواست یافت نشد یا در دسترس شما نیست.', 'didar' ), 'error' );
		}
		if ( ! $this->service->is_owner_editable( $submission_id, $user_id ) ) {
			return $this->notice( __( 'درخواست تکمیل‌شده دیگر قابل ویرایش نیست.', 'didar' ), 'warning' );
		}

		$form_type   = get_post_meta( $submission_id, '_didar_form_type', true );
		$form        = $this->registry->get( $form_type );
		$return_url  = $this->requested_return_url();
		$details_url = $this->get_submission_page_url( 'details_page_id', $submission_id, $return_url );
		if ( ! $form ) {
			return $this->notice( __( 'درخواست معتبر نیست.', 'didar' ), 'error' );
		}
		if ( ! $details_url ) {
			return $this->notice( __( 'صفحه مشاهده جزئیات هنوز توسط مدیر تنظیم نشده است.', 'didar' ), 'error' );
		}

		$state       = isset( $this->edit_state[ $submission_id ] ) ? $this->edit_state[ $submission_id ] : array();
		$values      = isset( $state['values'] ) ? $state['values'] : $this->service->get_fields( $submission_id );
		$shared_note = isset( $state['shared_note'] ) ? $state['shared_note'] : $this->service->get_shared_note( $submission_id );
		$errors      = isset( $state['errors'] ) ? $state['errors'] : array();

		ob_start();
		echo '<div class="didar-app" dir="rtl"><form class="didar-form didar-edit-form" method="post" data-didar-form data-form-type="' . esc_attr( $form_type ) . '" novalidate>';
		echo '<header class="didar-form-header"><p class="didar-eyebrow">' . esc_html__( 'ویرایش درخواست', 'didar' ) . '</p><h2>' . esc_html( $form['label'] ) . ' <span class="didar-heading-id">#' . esc_html( $submission_id ) . '</span></h2><p>' . esc_html__( 'تا پیش از تکمیل نهایی درخواست، می‌توانید اطلاعات و یادداشت مشترک را اصلاح کنید.', 'didar' ) . '</p></header>';
		if ( $errors ) {
			$this->render_error_summary( $errors, $form_type );
		}
		wp_nonce_field( 'didar_edit_submission_' . $submission_id, 'didar_nonce' );
		echo '<input type="hidden" name="didar_action" value="update_submission"><input type="hidden" name="didar_submission_id" value="' . esc_attr( $submission_id ) . '"><input type="hidden" name="didar_return" value="' . esc_attr( $return_url ) . '">';
		$this->renderer->render_sections( $form, $values, $errors, 'frontend' );
		$this->render_shared_note_field( $shared_note, isset( $errors['shared_note'] ) ? $errors['shared_note'] : '' );
		echo '<div class="didar-actions"><button type="submit" class="didar-submit"><span>' . esc_html__( 'ذخیره تغییرات', 'didar' ) . '</span><span class="didar-spinner" aria-hidden="true"></span></button><a class="didar-button didar-button--secondary" href="' . esc_url( $details_url ) . '">' . esc_html__( 'انصراف', 'didar' ) . '</a></div></form></div>';

		return ob_get_clean();
	}

	private function requested_submission_id() {
		return isset( $_GET['didar_submission'] ) && ! is_array( $_GET['didar_submission'] ) ? absint( wp_unslash( $_GET['didar_submission'] ) ) : 0;
	}

	private function get_page_settings() {
		$settings = get_option( self::PAGE_SETTINGS_OPTION, array() );
		return is_array( $settings ) ? $settings : array();
	}

	private function get_submission_page_url( $page_key, $submission_id, $return_url = '' ) {
		$settings = $this->get_page_settings();
		$page_id  = isset( $settings[ $page_key ] ) ? absint( $settings[ $page_key ] ) : 0;
		if ( ! $page_id || 'page' !== get_post_type( $page_id ) || 'publish' !== get_post_status( $page_id ) ) {
			return '';
		}

		$args = array( 'didar_submission' => absint( $submission_id ) );
		if ( $return_url ) {
			$args['didar_return'] = wp_validate_redirect( $return_url, home_url( '/' ) );
		}

		return add_query_arg( $args, get_permalink( $page_id ) );
	}

	private function requested_return_url() {
		$raw = '';
		if ( isset( $_GET['didar_return'] ) && ! is_array( $_GET['didar_return'] ) ) {
			$raw = wp_unslash( $_GET['didar_return'] );
		} elseif ( isset( $_POST['didar_return'] ) && ! is_array( $_POST['didar_return'] ) ) {
			$raw = wp_unslash( $_POST['didar_return'] );
		}

		return wp_validate_redirect( esc_url_raw( $raw ), home_url( '/' ) );
	}

	private function render_detail_sections( $form, $values ) {
		foreach ( $form['sections'] as $section ) {
			$visible_fields = array_filter(
				$section['fields'],
				function ( $field ) {
					return empty( $field['internal'] ) && 'honeypot' !== $field['type'];
				}
			);
			if ( ! $visible_fields ) {
				continue;
			}

			echo '<section class="didar-detail-section"><h3>' . esc_html( $section['label'] ) . '</h3><dl class="didar-detail-grid">';
			foreach ( $visible_fields as $field ) {
				$value     = array_key_exists( $field['name'], $values ) ? $values[ $field['name'] ] : '';
				$formatted = $this->service->format_value( $field, $value );
				echo '<div class="didar-detail-item"><dt>' . esc_html( $field['label'] ) . '</dt><dd>' . nl2br( esc_html( $formatted ) ) . '</dd></div>';
			}
			echo '</dl></section>';
		}
	}

	private function render_shared_note_field( $value = '', $error = '' ) {
		$described = $error ? ' aria-describedby="didar-shared-note-error" aria-invalid="true"' : '';
		echo '<fieldset class="didar-section didar-note-section"><legend>' . esc_html__( 'یادداشت', 'didar' ) . '</legend><div class="didar-grid"><div class="didar-field didar-field--textarea didar-field--wide' . ( $error ? ' didar-field--error' : '' ) . '">';
		echo '<label class="didar-label" for="didar-shared-note">' . esc_html__( 'یادداشت مشترک', 'didar' ) . '</label>';
		echo '<textarea id="didar-shared-note" name="didar_shared_note" rows="5"' . $described . '>' . esc_textarea( $value ) . '</textarea>';
		echo '<p class="didar-description">' . esc_html__( 'این یادداشت برای شما و مدیران قابل مشاهده و ویرایش است.', 'didar' ) . '</p>';
		if ( $error ) {
			echo '<p class="didar-error" id="didar-shared-note-error" role="alert">' . esc_html( $error ) . '</p>';
		}
		echo '</div></div></fieldset>';
	}

	private function render_error_summary( $errors, $type ) {
		echo '<div class="didar-error-summary" role="alert" tabindex="-1" data-didar-errors><h3>' . esc_html__( 'لطفاً خطاهای زیر را برطرف کنید:', 'didar' ) . '</h3><ul>';
		$fields = $this->registry->fields( $type );
		foreach ( $errors as $name => $message ) {
			if ( 'shared_note' === $name ) {
				echo '<li><a href="#didar-shared-note">' . esc_html( $message ) . '</a></li>';
			} elseif ( '_form' === $name || ! isset( $fields[ $name ] ) ) {
				echo '<li>' . esc_html( $message ) . '</li>';
			} else {
				echo '<li><a href="#didar-frontend-' . esc_attr( sanitize_html_class( $name ) ) . '">' . esc_html( $message ) . '</a></li>';
			}
		}
		echo '</ul></div>';
	}

	private function enqueue_assets() {
		wp_enqueue_style( 'didar-frontend', DIDAR_URL . 'assets/css/frontend.css', array(), DIDAR_VERSION );
		wp_enqueue_script( 'didar-frontend', DIDAR_URL . 'assets/js/frontend.js', array(), DIDAR_VERSION, true );
		if ( ! $this->assets_loaded ) {
			wp_localize_script( 'didar-frontend', 'didarConfig', array( 'ajaxUrl' => admin_url( 'admin-ajax.php' ), 'uploadNonce' => wp_create_nonce( 'didar_upload_file' ), 'messages' => array( 'uploading' => __( 'در حال بارگذاری…', 'didar' ), 'uploadError' => __( 'بارگذاری فایل انجام نشد.', 'didar' ), 'working' => __( 'در حال ثبت…', 'didar' ) ) ) );
			$this->assets_loaded = true;
		}
	}

	private function notice( $message, $type ) {
		return '<div class="didar-app" dir="rtl"><div class="didar-notice didar-notice--' . esc_attr( $type ) . '" role="alert">' . esc_html( $message ) . '</div></div>';
	}
}
