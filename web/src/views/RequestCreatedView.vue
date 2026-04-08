<script setup>
import { useRoute, useRouter } from 'vue-router'
import { CheckCircle2, ArrowRight, ArrowLeft } from 'lucide-vue-next'

const route = useRoute()
const router = useRouter()
const id = route.params.id
</script>

<template>
  <div id="request-created-wrap" class="created-wrap">
    <div id="request-created-card" class="card created-card">
      <div class="success-circle">
        <CheckCircle2 :size="40" />
      </div>
      <h1 id="request-created-title" class="created-title">Děkujeme, požadavek jsme přijali.</h1>
      <p id="request-created-subtitle" class="created-subtitle">
        Co nejdříve se vám ozveme a průběh budete vidět i zde v portálu.
      </p>
      <div id="request-created-actions" class="created-actions">
        <button id="request-created-back" class="btn btn-outline" @click="router.push('/zadosti')">
          <ArrowLeft :size="16" />
          <span>Zpět na přehled</span>
        </button>
        <button id="request-created-detail" class="btn btn-primary" @click="router.push(`/zadosti/${id}`)">
          <span>Zobrazit detail</span>
          <ArrowRight :size="16" />
        </button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.created-wrap {
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 60vh;
}
.created-card {
  max-width: 520px;
  width: 100%;
  text-align: center;
  padding: 48px 32px;
}
.success-circle {
  width: 72px;
  height: 72px;
  border-radius: 50%;
  background: var(--color-light);
  color: var(--color-primary);
  display: inline-flex;
  align-items: center;
  justify-content: center;
  margin-bottom: 20px;
}
.created-title {
  font-size: 22px;
  font-weight: 700;
  color: var(--color-primary);
  margin: 0 0 12px;
}
.created-subtitle {
  font-size: 14px;
  color: var(--color-gray-600);
  margin: 0 0 28px;
  line-height: 1.6;
}
.created-actions {
  display: flex;
  gap: 12px;
  justify-content: center;
  flex-wrap: wrap;
}
</style>
