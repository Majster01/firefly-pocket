<template>
  <div>
    <PageHeader title="Tags">
      <button class="btn small" @click="open(null)"><Icon name="plus" :size="18" /> Add</button>
    </PageHeader>

    <div v-if="!store.loaded.tags" class="loading"><span class="spinner" /></div>
    <div v-else-if="!store.tags.length" class="card empty"><h3>No tags yet</h3><p>Tags group transactions across categories, like a holiday.</p></div>
    <div v-else class="tag-grid">
      <button v-for="tag in store.tags" :key="tag.id" class="card tag-card" @click="open(tag)">
        <div class="row">
          <Icon name="tag" :size="18" />
          <strong class="grow ellipsis">{{ tag.attributes.tag }}</strong>
        </div>
        <p v-if="tag.attributes.description" class="small muted">{{ tag.attributes.description }}</p>
        <div class="num tag-total">{{ totals[tag.id] !== undefined ? money(totals[tag.id]) : '' }}</div>
      </button>
    </div>

    <Sheet :open="Boolean(editing)" :title="editing?.id ? 'Edit tag' : 'New tag'" @close="editing = null">
      <template #action><button class="btn small primary" :disabled="!editing?.tag.trim()" @click="save">Save</button></template>
      <template v-if="editing">
        <label class="field"><span>Tag</span><input v-model="editing.tag" autocapitalize="off" /></label>
        <label class="field"><span>Description</span><textarea v-model="editing.description" rows="2" /></label>
        <RouterLink v-if="editing.id" class="btn block" :to="{ path: '/transactions', query: { q: `tag_is:&quot;${editing.original}&quot;` } }">Show transactions</RouterLink>
        <button v-if="editing.id" class="btn block danger" style="margin-top: 10px" @click="remove">Delete tag</button>
      </template>
    </Sheet>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import PageHeader from '../components/PageHeader.vue'
import Icon from '../components/Icon.vue'
import Sheet from '../components/Sheet.vue'
import { del, get, post, put } from '../lib/api.js'
import { load, store } from '../lib/data.js'
import { money } from '../lib/format.js'
import { toast } from '../lib/toast.js'

const editing = ref(null)
const totals = ref({})

async function reload() {
  await load('tags', { force: true })
  // What was spent under each tag, all time (Pocket sums it)
  for (const tag of store.tags) {
    get('search/transactions/total', { query: `tag_is:"${tag.attributes.tag}"` }, { quiet: true })
      .then((json) => {
        const eur = (json?.data?.totals ?? []).find((item) => item.currency_code === 'EUR')
        totals.value = { ...totals.value, [tag.id]: eur ? eur.amount : 0 }
      })
      .catch(() => null)
  }
}

const open = (tag) => (editing.value = tag ? { id: tag.id, tag: tag.attributes.tag, original: tag.attributes.tag, description: tag.attributes.description ?? '' } : { id: null, tag: '', description: '' })

async function save() {
  const body = { tag: editing.value.tag.trim(), description: editing.value.description || null }
  if (editing.value.id) await put(`tags/${editing.value.id}`, body)
  else await post('tags', body)
  toast('Saved')
  editing.value = null
  reload()
}

async function remove() {
  if (!confirm(`Delete the tag ${editing.value.original}?`)) return
  await del(`tags/${editing.value.id}`)
  editing.value = null
  reload()
}

onMounted(reload)
</script>

<style>
.tag-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(min(100%, 220px), 1fr));
  gap: 12px;
}
.tag-grid .card + .card {
  margin-top: 0;
}
.tag-card {
  text-align: left;
  border-radius: var(--radius);
}
.tag-card p {
  margin: 6px 0 0;
}
.tag-total {
  margin-top: 10px;
  font-size: 20px;
  font-weight: 700;
}
</style>
