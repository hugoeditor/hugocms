<script setup>
import { useI18n } from 'vue-i18n'
import { setLocale, SUPPORTED_LOCALES } from '../i18n'

const { locale } = useI18n()
</script>

<template>
  <v-menu>
    <template #activator="{ props }">
      <v-btn variant="text" size="small" prepend-icon="mdi-translate" v-bind="props">
        {{ locale.toUpperCase() }}
      </v-btn>
    </template>
    <v-list density="compact" min-width="140">
      <v-list-item
        v-for="l in SUPPORTED_LOCALES"
        :key="l"
        :active="locale === l"
        :title="$t(`language.${l}`)"
        @click="setLocale(l)"
      />
    </v-list>
  </v-menu>
</template>
