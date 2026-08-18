<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Didar_Shortcodes {
	private $registry;
	private $renderer;
	private $validator;
	private $service;
	private $assets_loaded = false;

	public function __construct( Didar_Form_Registry $registry, Didar_Field_Renderer $renderer, Didar_Validator $validator, Didar_Submission_Service $service ) {
		$this->registry  = $registry;
		$this->renderer  = $renderer;
		$this->validator = $validator;
		$this->service   = $service;

		add_shortcode( 'didar_form', array( $this, 'form_shortcode' ) );
		add_shortcode( 'didar_submissions', array( $this, 'submissions_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_assets' ) );
	}

	public function maybe_enqueue_assets() {
		global $post;
		if ( $post instanceof WP_Post && ( has_shortcode( $post->post_content, 'didar_form' ) || has_shortcode( $post->post_content, 'didar_submissions' ) ) ) {
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

		if ( isset( $_POST['didar_action'], $_POST['didar_form_type'] ) && 'submit_form' === sanitize_key( wp_unslash( $_POST['didar_action'] ) ) && $type === sanitize_key( wp_unslash( $_POST['didar_form_type'] ) ) ) {
			$nonce = isset( $_POST['didar_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['didar_nonce'] ) ) : '';
			if ( ! wp_verify_nonce( $nonce, 'didar_submit_form_' . $type ) ) {
				$errors['_form'] = __( 'نشست شما منقضی شده است؛ صفحه را تازه‌سازی کنید.', 'didar' );
			} else {
				$token  = isset( $_POST['didar_request_token'] ) ? sanitize_key( wp_unslash( $_POST['didar_request_token'] ) ) : '';
				$key    = 'didar_submit_' . get_current_user_id() . '_' . $token;
				$cached = $token ? absint( get_transient( $key ) ) : 0;
				if ( $cached && Didar_Post_Type::POST_TYPE === get_post_type( $cached ) ) {
					$submission_id = $cached;
				} else {
					$raw    = isset( $_POST['didar_fields'] ) && is_array( $_POST['didar_fields'] ) ? wp_unslash( $_POST['didar_fields'] ) : array();
					$result = $this->validator->validate( $type, $raw, 'frontend' );
					$values = array_intersect_key( $raw, $this->registry->fields( $type ) );
					$errors = $result['errors'];
					if ( $result['valid'] ) {
						$created = $this->service->create( $type, $result['data'], get_current_user_id() );
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
			echo '<div class="didar-table-wrap"><table class="didar-table"><thead><tr><th scope="col">' . esc_html__( 'شماره', 'didar' ) . '</th><th scope="col">' . esc_html__( 'نوع فرم', 'didar' ) . '</th><th scope="col">' . esc_html__( 'تاریخ ثبت', 'didar' ) . '</th><th scope="col">' . esc_html__( 'وضعیت', 'didar' ) . '</th></tr></thead><tbody>';
			while ( $query->have_posts() ) {
				$query->the_post();
				$post_id   = get_the_ID();
				$form_type = get_post_meta( $post_id, '_didar_form_type', true );
				$form       = $this->registry->get( $form_type );
				$status     = get_post_meta( $post_id, '_didar_status', true );
				echo '<tr><td data-label="' . esc_attr__( 'شماره', 'didar' ) . '"><strong>#' . esc_html( $post_id ) . '</strong></td><td data-label="' . esc_attr__( 'نوع فرم', 'didar' ) . '">' . esc_html( $form ? $form['label'] : $form_type ) . '</td><td data-label="' . esc_attr__( 'تاریخ ثبت', 'didar' ) . '"><time datetime="' . esc_attr( get_the_date( DATE_W3C ) ) . '">' . esc_html( get_the_date() ) . '</time></td><td data-label="' . esc_attr__( 'وضعیت', 'didar' ) . '"><span class="didar-status didar-status--' . esc_attr( sanitize_html_class( $status ) ) . '">' . esc_html( $this->service->get_status_label( $status ) ) . '</span></td></tr>';
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

	private function render_error_summary( $errors, $type ) {
		echo '<div class="didar-error-summary" role="alert" tabindex="-1" data-didar-errors><h3>' . esc_html__( 'لطفاً خطاهای زیر را برطرف کنید:', 'didar' ) . '</h3><ul>';
		$fields = $this->registry->fields( $type );
		foreach ( $errors as $name => $message ) {
			if ( '_form' === $name || ! isset( $fields[ $name ] ) ) {
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
