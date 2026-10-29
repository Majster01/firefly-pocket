import { get, keyBy } from 'lodash-es'
import ApiTransformer from '~/transformers/ApiTransformer'
import RuleSuggestion from '~/models/RuleSuggestion'
import Category from '~/models/Category'
import { useCategoryStore } from '~/stores/categoryStore'
import { useAccountStore } from '~/stores/accountStore'

export default class RuleSuggestionTransformer extends ApiTransformer {
  static transformFromApi(item) {
    if (!item) {
      return null
    }
    const categoryStore = useCategoryStore()
    const accountStore = useAccountStore()

    // The suggester proposes a category by name => use the existing category, or a new one Firefly creates on save
    const categoryName = get(item, 'attributes.category') ?? ''
    const category = categoryStore.categoryList.find((category) => Category.getDisplayName(category)?.toLowerCase() === categoryName.toLowerCase())
    item.attributes.category = category ?? { attributes: { name: categoryName } }

    const triggerTypes = keyBy(RuleSuggestion.triggerTypesList(), 'code')
    item.attributes.triggers = get(item, 'attributes.triggers', []).map((trigger) => ({
      type: triggerTypes[trigger.type],
      value: trigger.value,
      account: trigger.type === RuleSuggestion.triggerTypes.destinationAccountId.code ? (accountStore.accountDictionary[trigger.value] ?? null) : null,
    }))
    return item
  }

  static transformToApi(item) {
    if (!item) {
      return null
    }
    let data = get(item, 'attributes')
    return {
      category: Category.getDisplayName(get(data, 'category')),
      triggers: get(data, 'triggers', []).map((trigger) => ({
        type: get(trigger, 'type.code'),
        value: RuleSuggestion.isAccountTrigger(trigger) ? get(trigger, 'account.id') : trigger.value,
      })),
    }
  }
}
