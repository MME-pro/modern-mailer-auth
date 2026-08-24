<?php
/**
 * Declarative provider field definitions.
 *
 * @package ModernMailer
 */

namespace ModernMailer;

defined( 'ABSPATH' ) || exit;

/**
 * Describes one configuration field a provider needs.
 *
 * Eighteen providers is too many to hand-write eighteen settings panels for,
 * and hand-written panels drift: a field gets added to the handler and not to
 * the form, or is stored in the clear because the panel forgot it was a
 * secret. So each provider declares its fields and one renderer draws them.
 *
 * The declaration is also the contract the REST API publishes to the admin
 * app, so the front end never hard-codes a provider's field list either. Adding
 * a provider means adding a class, not touching the UI.
 *
 * `secret` is the load-bearing flag. A field marked secret is stored through
 * Secrets rather than Settings - encrypted at rest, never echoed back into a
 * form, and pinnable from wp-config.php. Getting that wrong is how API keys end
 * up in plain text in the options table, so it is a property of the field
 * itself rather than a decision the storage layer makes later.
 */
class Field {

	public const TEXT     = 'text';
	public const PASSWORD = 'password';
	public const EMAIL    = 'email';
	public const NUMBER   = 'number';
	public const TEXTAREA = 'textarea';
	public const SELECT   = 'select';
	public const RADIO    = 'radio';
	public const CHECKBOX = 'checkbox';
	public const READONLY = 'readonly';

	/** Share of the form row a field occupies. */
	public const FULL  = 'full';
	public const HALF  = 'half';
	public const THIRD = 'third';

	/**
	 * @param string                $key      Storage key, unique within the provider.
	 * @param string                $label    Human label.
	 * @param string                $type     One of the type constants.
	 * @param bool                  $secret   Store encrypted, never render the value.
	 * @param bool                  $required Refuse to verify the connection without it.
	 * @param string                $help     One line of guidance under the field.
	 * @param string                $placeholder Example value.
	 * @param array<string,string>  $options  Value => label, for SELECT.
	 * @param mixed                 $default  Default value.
	 * @param string                $constant wp-config.php constant that pins it, without the MMOA_ prefix.
	 * @param string                $width    FULL, HALF or THIRD - how much of a form row this takes.
	 * @param array                 $sets     Option value => [ other field key => value ] applied on choosing it.
	 * @param array                 $depends  [ 'field' => key, 'value' => value ] - disabled unless that field matches.
	 */
	public function __construct(
		public readonly string $key,
		public readonly string $label,
		public readonly string $type = self::TEXT,
		public readonly bool $secret = false,
		public readonly bool $required = false,
		public readonly string $help = '',
		public readonly string $placeholder = '',
		public readonly array $options = [],
		public readonly mixed $default = '',
		public readonly string $constant = '',
		public readonly string $width = self::FULL,
		public readonly array $sets = [],
		public readonly array $depends = []
	) {}

	/**
	 * Shorthand for a required credential.
	 */
	public static function secret( string $key, string $label, string $help = '', string $placeholder = '' ): self {
		return new self(
			key: $key,
			label: $label,
			type: self::PASSWORD,
			secret: true,
			required: true,
			help: $help,
			placeholder: $placeholder
		);
	}

	/**
	 * Shorthand for a required plain field.
	 */
	public static function required( string $key, string $label, string $help = '', string $placeholder = '' ): self {
		return new self(
			key: $key,
			label: $label,
			required: true,
			help: $help,
			placeholder: $placeholder
		);
	}

	/**
	 * The JSON shape the admin app consumes.
	 *
	 * A secret's value is never included - not even a masked one. The `is_set`
	 * flag is all the UI needs to render "stored, leave blank to keep it",
	 * and it is the only thing that can be published without putting the
	 * credential one XSS away from being read out of the page.
	 *
	 * @param mixed $value  Current value, ignored for secrets.
	 * @param bool  $is_set Whether a secret currently holds anything.
	 * @param bool  $locked Whether a wp-config.php constant pins it.
	 * @return array<string,mixed>
	 */
	public function to_array( mixed $value = null, bool $is_set = false, bool $locked = false ): array {
		return [
			'key'         => $this->key,
			'label'       => $this->label,
			'type'        => $this->type,
			'secret'      => $this->secret,
			'required'    => $this->required,
			'help'        => $this->help,
			'placeholder' => $this->placeholder,
			'options'     => $this->options,
			'locked'      => $locked,
			'is_set'      => $this->secret ? $is_set : ( '' !== (string) $value ),
			'value'       => $this->secret ? '' : $value,

			// Layout and behaviour, declared here so the form stays generated.
			// The alternative is a hand-written panel per provider, which is
			// what this whole schema exists to avoid.
			'width'       => $this->width,
			'sets'        => $this->sets,
			'depends'     => $this->depends,
		];
	}
}
