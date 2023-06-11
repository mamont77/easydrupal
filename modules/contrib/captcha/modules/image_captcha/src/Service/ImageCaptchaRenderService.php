<?php

namespace Drupal\image_captcha\Service;

use Drupal\captcha\Constants\CaptchaConstants;
use Drupal\Core\Url;
use Drupal\Core\Link;
use Drupal\image_captcha\Constants\ImageCaptchaConstants;

/**
 * Helper service to render specific parts of the image captcha.
 */
class ImageCaptchaRenderService {

  /**
   * Add image refresh button to captcha form element.
   *
   * @return array
   *   The processed element.
   *
   * @see image_captcha_element_info_alter()
   */
  public static function imageCaptchaAfterBuildProcess(array $element) {
    // Only proceed, if we can determine the form_id and the captcha type:
    if (!empty($element['#captcha_info']['form_id']) && !empty($element['#captcha_type'])) {
      // We need the form_id for regenerating the image captcha:
      $form_id = $element['#captcha_info']['form_id'];
      // Check if this is an image_captcha:
      $isImageCaptcha = $element['#captcha_type'] == ImageCaptchaConstants::IMAGE_CAPTCHA_CAPTCHA_TYPE;
      if ($isImageCaptcha && isset($element['captcha_widgets']['captcha_image_wrapper']['captcha_image'])) {
        $uri = Link::fromTextAndUrl(t('Get new captcha!'),
        new Url('image_captcha.refresh',
          ['form_id' => $form_id],
          ['attributes' => ['class' => ['reload-captcha']]]
        )
        );
        $element['captcha_widgets']['captcha_image_wrapper']['captcha_refresh'] = [
          '#theme' => 'image_captcha_refresh',
          '#captcha_refresh_link' => $uri,
        ];
      }
    }
    else {
      \Drupal::service('logger.factory')->get('image_captcha')->error('Missing required form ID on route @route', [
        '@route' => \Drupal::routeMatch()->getRouteName() ?? 'Unknown',
      ]);
    }
    return $element;
  }

}
