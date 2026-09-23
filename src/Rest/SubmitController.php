<?php

namespace EmbedForms\Rest;

use EmbedForms\Db\Forms;
use EmbedForms\Submit\SubmissionHandler;

/**
 * POST /embed-forms/v1/forms/{ref}/submit, called by the public form page.
 * Open to anyone: the page's signed token, the rate limit and Turnstile
 * guard it, since the iframe has no WordPress cookies to prove anything.
 */
final class SubmitController {

  public const NAMESPACE = 'embed-forms/v1';

  public function register(): void {
    register_rest_route(self::NAMESPACE, '/forms/(?P<ref>[A-Za-z0-9_-]+)/submit', [
      'methods' => 'POST',
      'callback' => [$this, 'submit'],
      'permission_callback' => '__return_true',
    ]);
  }

  public function submit(\WP_REST_Request $request): \WP_REST_Response {
    $form = Forms::findPublic((string) $request['ref']);
    if (!$form) {
      return new \WP_REST_Response(['ok' => FALSE, 'message' => __('This form does not exist.', 'embed-forms')], 404);
    }
    $params = $request->get_json_params();
    if (!is_array($params)) {
      $params = $request->get_body_params();
    }
    $result = (new SubmissionHandler())->handle($form, is_array($params) ? $params : [], current_user_can('manage_options'));
    $response = new \WP_REST_Response($result['body'], $result['status']);
    $response->header('Cache-Control', 'no-store');
    return $response;
  }

}
