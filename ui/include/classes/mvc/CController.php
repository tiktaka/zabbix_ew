<?php
/*
** Zabbix
** Copyright (C) 2001-2023 Zabbix SIA
**
** This program is free software; you can redistribute it and/or modify
** it under the terms of the GNU General Public License as published by
** the Free Software Foundation; either version 2 of the License, or
** (at your option) any later version.
**
** This program is distributed in the hope that it will be useful,
** but WITHOUT ANY WARRANTY; without even the implied warranty of
** MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
** GNU General Public License for more details.
**
** You should have received a copy of the GNU General Public License
** along with this program; if not, write to the Free Software
** Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
**/


abstract class CController {

	protected const POST_CONTENT_TYPE_FORM = 0;
	protected const POST_CONTENT_TYPE_JSON = 1;

	protected const VALIDATION_OK = 0;
	protected const VALIDATION_ERROR = 1;
	protected const VALIDATION_FATAL_ERROR = 2;

	/**
	 * Content type of the POST request.
	 *
	 * @var int
	 */
	private $post_content_type = self::POST_CONTENT_TYPE_FORM;

	/**
	 * Action name, so that controller knows what action he is executing.
	 *
	 * @var string
	 */
	private $action;

	/**
	 * Response object generated by controller.
	 *
	 * @var CControllerResponse
	 */
	private $response;

	/**
	 * Result of input validation, one of VALIDATION_OK, VALIDATION_ERROR, VALIDATION_FATAL_ERROR.
	 *
	 * @var int
	 */
	private $validation_result;

	/**
	 * Non-validated input parameters.
	 *
	 * @var array|null
	 */
	private $raw_input;

	/**
	 * Validated input parameters.
	 *
	 * @var array
	 */
	protected $input = [];

	/**
	 * Validate CSRF token flag, if true CSRF token must be validated.
	 *
	 * @var bool
	 */
	private bool $validate_csrf_token = true;

	public function __construct() {
		$this->init();
		$this->populateRawInput();
	}

	/**
	 * Initialization function that can be overridden later.
	 */
	protected function init() {
	}

	/**
	 * Get content type of the POST request.
	 *
	 * @return int
	 */
	protected function getPostContentType(): int {
		return $this->post_content_type;
	}

	/**
	 * Set content type of the POST request.
	 *
	 * @param int $post_content_type
	 */
	protected function setPostContentType(int $post_content_type): void {
		$this->post_content_type = $post_content_type;
	}

	/**
	 * Return controller action name.
	 *
	 * @return string
	 */
	public function getAction() {
		return $this->action;
	}

	/**
	 * Set controller action name.
	 *
	 * @param string $action
	 */
	public function setAction($action) {
		$this->action = $action;
	}

	/**
	 * Return controller response object.
	 *
	 * @return CControllerResponse
	 */
	public function getResponse() {
		return $this->response;
	}

	/**
	 * Set controller response.
	 *
	 * @param CControllerResponse $response
	 */
	protected function setResponse($response) {
		$this->response = $response;
	}

	/**
	 * Return debug mode.
	 *
	 * @return bool
	 */
	protected function getDebugMode() {
		return CWebUser::getDebugMode();
	}

	/**
	 * Return user type.
	 *
	 * @return int
	 */
	protected function getUserType() {
		return CWebUser::getType();
	}

	/**
	 * Checks access of current user to specific access rule.
	 *
	 * @param string $rule_name  Rule name.
	 *
	 * @return bool  Returns true if user has access to rule, false - otherwise.
	 */
	protected function checkAccess(string $rule_name): bool {
		return CWebUser::checkAccess($rule_name);
	}

	/**
	 * Disables CSRF token validation.
	 *
	 * @return void
	 */
	protected function disableCsrfValidation(): void {
		$this->validate_csrf_token = false;
	}

	/**
	 * Validate session ID (SID).
	 *
	 * @deprecated
	 */
	protected function disableSIDvalidation() {
		$this->validate_csrf_token = false;
		trigger_error(
			_s('Method %1$s is deprecated, use %2$s instead.', 'disableSIDvalidation', 'disableCsrfValidation'),
			E_USER_DEPRECATED
		);
	}

	/**
	 * @return array
	 */
	private static function getFormInput(): array {
		static $input;

		if ($input === null) {
			$input = $_REQUEST;

			if (hasRequest('formdata')) {
				$data = base64_decode(getRequest('data'));
				$sign = base64_decode(getRequest('sign'));
				$request_sign = CEncryptHelper::sign($data);

				if (CEncryptHelper::checkSign($sign, $request_sign)) {
					$data = json_decode($data, true);

					if ($data['messages']) {
						CMessageHelper::setScheduleMessages($data['messages']);
					}

					$input = array_replace($input, $data['form']);
				}
				else {
					info(_('Operation cannot be performed due to unauthorized request.'));
				}

				// Replace window.history to avoid resubmission warning dialog.
				zbx_add_post_js("history.replaceState({}, '');");
			}
		}

		return $input;
	}

	/**
	 * @return array
	 */
	private static function getJsonInput(): array {
		static $input;

		if ($input === null) {
			$input = $_REQUEST;

			$json_input = json_decode(file_get_contents('php://input'), true);

			if (is_array($json_input)) {
				$input += $json_input;
			}
			else {
				info(_('JSON array input is expected.'));
			}
		}

		return $input;
	}

	/**
	 * Validate input parameters.
	 *
	 * @param array $validation_rules
	 *
	 * @return bool
	 */
	protected function validateInput(array $validation_rules): bool {
		if ($this->raw_input === null) {
			$this->validation_result = self::VALIDATION_FATAL_ERROR;

			return false;
		}

		$validator = new CNewValidator($this->raw_input, $validation_rules);

		foreach ($validator->getAllErrors() as $error) {
			info($error);
		}

		if ($validator->isErrorFatal()) {
			$this->validation_result = self::VALIDATION_FATAL_ERROR;
		}
		else {
			$this->input = $validator->getValidInput();
			$this->validation_result = $validator->isError() ? self::VALIDATION_ERROR : self::VALIDATION_OK;
		}

		return $this->validation_result == self::VALIDATION_OK;
	}

	/**
	 * Validate "from" and "to" parameters for allowed period.
	 *
	 * @return bool
	 */
	protected function validateTimeSelectorPeriod() {
		if (!$this->hasInput('from') || !$this->hasInput('to')) {
			return true;
		}

		try {
			$max_period = 'now-'.CSettingsHelper::get(CSettingsHelper::MAX_PERIOD);
		}
		catch (Exception $x) {
			access_deny(ACCESS_DENY_PAGE);

			return false;
		}

		$ts = [];
		$ts['now'] = time();
		$range_time_parser = new CRangeTimeParser();

		foreach (['from', 'to'] as $field) {
			$range_time_parser->parse($this->getInput($field));
			$ts[$field] = $range_time_parser
				->getDateTime($field === 'from')
				->getTimestamp();
		}

		$period = $ts['to'] - $ts['from'] + 1;
		$range_time_parser->parse($max_period);
		$max_period = 1 + $ts['now'] - $range_time_parser
			->getDateTime(true)
			->getTimestamp();

		if ($period < ZBX_MIN_PERIOD) {
			info(_n('Minimum time period to display is %1$s minute.',
				'Minimum time period to display is %1$s minutes.', (int) (ZBX_MIN_PERIOD / SEC_PER_MIN)
			));

			return false;
		}
		elseif ($period > $max_period) {
			info(_n('Maximum time period to display is %1$s day.',
				'Maximum time period to display is %1$s days.', (int) round($max_period / SEC_PER_DAY)
			));

			return false;
		}

		return true;
	}

	/**
	 * Return validation result.
	 *
	 * @return int
	 */
	protected function getValidationError() {
		return $this->validation_result;
	}

	/**
	 * Check if input parameter exists.
	 *
	 * @param string $var
	 *
	 * @return bool
	 */
	protected function hasInput($var) {
		return array_key_exists($var, $this->input);
	}

	/**
	 * Get single input parameter.
	 *
	 * @param string $var
	 * @param mixed $default
	 *
	 * @return mixed
	 */
	protected function getInput($var, $default = null) {
		if ($default === null) {
			return $this->input[$var];
		}
		else {
			return array_key_exists($var, $this->input) ? $this->input[$var] : $default;
		}
	}

	/**
	 * Get several input parameters.
	 *
	 * @param array $var
	 * @param array $names
	 */
	protected function getInputs(&$var, $names) {
		foreach ($names as $name) {
			if ($this->hasInput($name)) {
				$var[$name] = $this->getInput($name);
			}
		}
	}

	/**
	 * Return all input parameters.
	 *
	 * @return array
	 */
	protected function getInputAll() {
		return $this->input;
	}

	/**
	 * Check user permissions.
	 *
	 * @abstract
	 *
	 * @return bool
	 */
	abstract protected function checkPermissions();

	/**
	 * Validate input parameters.
	 *
	 * @abstract
	 *
	 * @return bool
	 */
	abstract protected function checkInput();

	/**
	 * Checks if CSRF token in the request is valid.
	 *
	 * @return bool
	 */
	private function checkCsrfToken(): bool {
		if (!is_array($this->raw_input) || !array_key_exists(CCsrfTokenHelper::CSRF_TOKEN_NAME, $this->raw_input)) {
			return false;
		}

		$skip = ['popup', 'massupdate'];

		foreach (explode('.', $this->action) as $segment) {
			if (!in_array($segment, $skip, true)) {
				return CCsrfTokenHelper::check($this->raw_input[CCsrfTokenHelper::CSRF_TOKEN_NAME], $segment);
			}
		}

		return false;
	}

	/**
	 * Execute action and generate response object.
	 *
	 * @abstract
	 */
	abstract protected function doAction();

	private function populateRawInput(): void {
		switch ($this->getPostContentType()) {
			case self::POST_CONTENT_TYPE_FORM:
				$this->raw_input = self::getFormInput();
				break;

			case self::POST_CONTENT_TYPE_JSON:
				$this->raw_input = self::getJsonInput();
				break;

			default:
				$this->raw_input = null;
		}
	}

	/**
	 * Main controller processing routine. Returns response object: data, redirect or fatal redirect.
	 *
	 * @throws CAccessDeniedException
	 *
	 * @return CControllerResponse|null
	 */
	final public function run(): ?CControllerResponse {
		if ($this->validate_csrf_token && !$this->checkCsrfToken()) {
			throw new CAccessDeniedException();
		}

		if ($this->checkInput()) {
			if ($this->checkPermissions() !== true) {
				throw new CAccessDeniedException();
			}

			$this->doAction();
		}

		return $this->getResponse();
	}
}
