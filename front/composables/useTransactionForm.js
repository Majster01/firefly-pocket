import { get, head, isEqual } from 'lodash-es'
import { addDays } from 'date-fns'
import Account from '~/models/Account'
import Category from '~/models/Category'
import Tag from '~/models/Tag'
import Transaction from '~/models/Transaction'
import { transactionFormField, transactionExtraDateFieldList } from '~/constants/TransactionConstants.js'
import { generateChildren } from '~/utils/VueUtils'
import { isStringEmpty } from '~/utils/DataUtils'
import { useCategoryStore } from '~/stores/categoryStore'

const transactionPathKey = 'attributes.transactions.0'

export const useTransactionForm = ({ item, itemId, profileStore = useProfileStore() }) => {
  const categoryStore = useCategoryStore()

  const { amount, amountForeign, date, tags, description, notes, budget, piggyBank, accountSource, accountDestination, category, type, currencyForeign } = generateChildren(item, [
    { computed: 'amount', parentKey: `${transactionPathKey}.amount` },
    { computed: 'amountForeign', parentKey: `${transactionPathKey}.amountForeign` },
    { computed: 'currencyForeign', parentKey: `${transactionPathKey}.currencyForeign` },
    { computed: 'date', parentKey: `${transactionPathKey}.date` },
    { computed: 'tags', parentKey: `${transactionPathKey}.tags` },
    { computed: 'description', parentKey: `${transactionPathKey}.description` },
    { computed: 'notes', parentKey: `${transactionPathKey}.notes` },
    { computed: 'accountSource', parentKey: `${transactionPathKey}.accountSource` },
    { computed: 'accountDestination', parentKey: `${transactionPathKey}.accountDestination` },
    { computed: 'category', parentKey: `${transactionPathKey}.category` },
    { computed: 'type', parentKey: `${transactionPathKey}.type` },
    { computed: 'budget', parentKey: `${transactionPathKey}.budget` },
    { computed: 'piggyBank', parentKey: `${transactionPathKey}.piggyBank` },
  ])

  const extraDates = generateChildren(
    item,
    transactionExtraDateFieldList.map((field) => ({ computed: field.code, parentKey: `${transactionPathKey}.${field.code}` })),
  )

  const isTypeExpense = computed(() => isEqual(type.value, Transaction.types.expense))
  const isTypeIncome = computed(() => isEqual(type.value, Transaction.types.income))
  const isTypeTransfer = computed(() => isEqual(type.value, Transaction.types.transfer))

  const transactions = computed(() => get(item.value, 'attributes.transactions', []))
  const isSplitTransaction = computed(() => transactions.value.length > 1)
  const accountSourceAllowedTypes = computed(() => Account.getAccountTypesForTransactionTypeSource(type.value))
  const accountDestinationAllowedTypes = computed(() => Account.getAccountTypesForTransactionTypeDestination(type.value))
  const sourceCurrency = computed(() => Account.getCurrency(accountSource.value))

  const isForeignAmountVisible = computed(() => {
    const newTransactionWithDefaultCurrency = !itemId.value && (profileStore.defaultForeignCurrency || profileStore.isForeignCurrencyAlwaysVisible)
    const areTypeAssetsWithDifferentCurrencies =
      accountSource.value &&
      accountDestination.value &&
      Account.getType(accountSource.value)?.fireflyCode === Account.types.asset.fireflyCode &&
      Account.getType(accountDestination.value)?.fireflyCode === Account.types.asset.fireflyCode &&
      Account.getCurrency(accountSource.value)?.id !== Account.getCurrency(accountDestination.value)?.id
    return !!(newTransactionWithDefaultCurrency || areTypeAssetsWithDifferentCurrencies || currencyForeign.value || amountForeign.value)
  })

  const attemptAccountsFix = () => {
    let { source, destination } = Transaction.attemptAccountFixOnTypeChange(type.value, accountSource.value, accountDestination.value)
    accountSource.value = source
    accountDestination.value = destination
  }

  watch(category, async (newValue) => {
    if (!profileStore.copyCategoryToDescription || !isStringEmpty(description.value) || itemId.value || !newValue) {
      return
    }
    description.value = Category.getDisplayName(newValue)
  })

  watch(tags, async (newValue) => {
    if (itemId.value || !newValue) {
      return
    }

    // Give child tags more priority for more granularity
    const sortedTagNames = sortByPath(newValue, 'level', false).map((tag) => Tag.getDisplayNameEllipsized(tag))

    if (profileStore.copyTagToDescription && isStringEmpty(description.value)) {
      // The first one is the one with the highest level
      let descriptionValue = head(sortedTagNames) ?? ''
      description.value = profileStore.lowerCaseTransactionDescription ? descriptionValue.toLowerCase() : descriptionValue
    }

    if (profileStore.copyTagToCategory && !category.value) {
      for (let tagName of sortedTagNames) {
        let foundCategory = categoryStore.categoryList.find((c) => tagName.toLowerCase() === Category.getDisplayName(c).toLowerCase())
        if (foundCategory) {
          category.value = foundCategory
          break
        }
      }
    }
  })

  watch([item, type], ([currentItem, newValue], [previousItem, oldValue]) => {
    // Ignore form hydration, but repair account directions after a user changes
    // the type of a non-split transaction, including an existing one.
    if (currentItem !== previousItem || isSplitTransaction.value || !oldValue || isEqual(newValue, oldValue)) {
      return
    }
    attemptAccountsFix()
  })

  watch(description, (newValue) => {
    if (isSplitTransaction.value) {
      return
    }

    newValue = newValue ?? ''
    if (profileStore.lowerCaseTransactionDescription) {
      newValue = newValue.toLowerCase()
    }
    if (profileStore.stripAccents) {
      newValue = LanguageUtils.removeAccents(newValue)
    }
    description.value = newValue
  })

  const onSubDay = () => {
    date.value = addDays(date.value, -1)
  }

  const onToday = () => {
    date.value = new Date()
  }

  const onAddDay = () => {
    date.value = addDays(date.value, 1)
  }

  const getStyleForField = (fieldType) => {
    const fieldCode = fieldType.code
    const position = profileStore.transactionFormFieldsConfig.findIndex((item) => item.code === fieldCode)
    const field = profileStore.transactionFormFieldsConfig.find((item) => item.code === fieldCode)
    // Fields missing from the saved config (added in a newer app version) keep their default visibility
    const isVisible = field ? field.isVisible : fieldType.isVisible
    const displayStyle = isVisible ? '' : 'display: none'

    if (isTypeExpense.value) {
      return `order: ${position}; ${displayStyle}`
    }

    if (isTypeIncome.value) {
      let position = profileStore.transactionFormFieldsConfig.findIndex((item) => item.code === fieldCode)
      if (fieldCode === transactionFormField.sourceAccount.code) {
        position = profileStore.transactionFormFieldsConfig.findIndex((item) => item.code === transactionFormField.destinationAccount.code)
      }
      if (fieldCode === transactionFormField.destinationAccount.code) {
        position = profileStore.transactionFormFieldsConfig.findIndex((item) => item.code === transactionFormField.sourceAccount.code)
      }
      return `order: ${position}; ${displayStyle}`
    }

    if (isTypeTransfer.value) {
      if ([transactionFormField.sourceAccount.code, transactionFormField.destinationAccount.code].includes(fieldCode)) {
        return `order: 0`
      }
      const position = profileStore.transactionFormFieldsConfig.findIndex((item) => item.code === fieldCode)
      return `order: ${position}; ${displayStyle}`
    }

    return `order: 1`
  }

  const accountSourceBinding = computed(() => {
    const isRequired = isTypeExpense.value || isTypeTransfer.value
    return {
      required: isRequired,
      rules: isRequired ? [{ required: true, message: 'Source account is required!' }] : [],
    }
  })

  const accountDestinationBinding = computed(() => {
    const isRequired = isTypeIncome.value || isTypeTransfer.value
    return {
      required: isRequired,
      rules: isRequired ? [{ required: true, message: 'Destination account is required!' }] : [],
    }
  })

  const showSourceAccountSuggestion = computed(() => !profileStore.defaultAccountSource && !accountSource.value)

  return {
    amount,
    amountForeign,
    date,
    extraDates,
    tags,
    description,
    notes,
    budget,
    piggyBank,
    accountSource,
    accountDestination,
    category,
    type,
    currencyForeign,
    isSplitTransaction,
    accountSourceAllowedTypes,
    accountDestinationAllowedTypes,
    sourceCurrency,
    isForeignAmountVisible,
    onSubDay,
    onToday,
    onAddDay,
    isTypeTransfer,
    getStyleForField,
    accountSourceBinding,
    accountDestinationBinding,
    showSourceAccountSuggestion,
  }
}
