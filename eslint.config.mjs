import js from "@eslint/js";
import globals from "globals";
import pluginVue from "eslint-plugin-vue";
import { defineConfigWithVueTs, vueTsConfigs, configureVueProject } from "@vue/eslint-config-typescript";

configureVueProject({ scriptLangs: ["ts", "js"] });

export default defineConfigWithVueTs(
  { ignores: ["resources/js/ziggy.js"] },
  js.configs.recommended,
  { files: ["**/*.{js,mjs,cjs,vue}"], languageOptions: { globals: globals.browser } },
  pluginVue.configs["flat/essential"],
  vueTsConfigs.base,
);
