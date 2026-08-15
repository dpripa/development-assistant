export default {
	extends: [ 'stylelint-config-standard' ],
	rules: {
		'custom-property-pattern': '^da-[a-z0-9-]+$',
		'no-descending-specificity': null,
		'selector-class-pattern': null,
		'selector-id-pattern': null,
	},
};
