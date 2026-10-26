<script setup>
import { avatarColor } from '../format.js'

defineProps({ id: [Number, String], initials: String, name: String, small: Boolean })
</script>

<template>
  <span class="avatar" :class="{ sm: small }" :style="{ background: avatarColor(id) }" :title="name" role="img" :aria-label="name">{{ initials }}</span>
</template>
