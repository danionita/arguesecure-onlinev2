/* eslint-env node */
require("@rushstack/eslint-patch/modern-module-resolution")

module.exports = {
    root: true,
    extends: [
        "plugin:vue/vue3-essential",
        "eslint:recommended",
        "@vue/eslint-config-typescript",
        "@vue/eslint-config-prettier"
    ],
    parserOptions: {
        ecmaVersion: "latest"
    },
    rules: {
        "vue/multi-word-component-names": "off",
        "no-undef": "off",
        // The underscore convention, honoured rather than worked around
        // (W66). `const { d4: _omit, ...rest } = defences` is how a test
        // removes one key to prove a shelved defence changes no total; the
        // binding exists only so the rest-spread can. Without this the rule
        // reports it, and the two ways out are both worse than the rule:
        // an inline disable comment, or dropping `--max-warnings 0` and
        // letting real unused variables through unnoticed.
        "@typescript-eslint/no-unused-vars": [
            "warn",
            { argsIgnorePattern: "^_", varsIgnorePattern: "^_", caughtErrorsIgnorePattern: "^_" }
        ]
    }
}
