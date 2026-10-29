import BaseModel from '~/models/BaseModel'
import RuleSuggestionTransformer from '~/transformers/RuleSuggestionTransformer'
import RuleSuggestionRepository from '~/repository/RuleSuggestionRepository'
import Account from '~/models/Account'
import { get, head } from 'lodash-es'

export default class RuleSuggestion extends BaseModel {
  getTransformer() {
    return RuleSuggestionTransformer
  }

  getRepository() {
    return new RuleSuggestionRepository()
  }

  getEmpty() {
    return {
      attributes: {
        category: null,
        triggers: [],
        transactions: [],
      },
    }
  }

  // --------

  static get triggerTypes() {
    return {
      descriptionContains: { code: 'description_contains', label: 'rule_suggestion_page.trigger_description_contains' },
      destinationContains: { code: 'destination_account_contains', label: 'rule_suggestion_page.trigger_destination_contains' },
      destinationIs: { code: 'destination_account_is', label: 'rule_suggestion_page.trigger_destination_is' },
      destinationAccountId: { code: 'destination_account_id', label: 'rule_suggestion_page.trigger_destination_account_id' },
    }
  }

  static triggerTypesList() {
    return Object.values(this.triggerTypes)
  }

  static isAccountTrigger(trigger) {
    return get(trigger, 'type.code') === this.triggerTypes.destinationAccountId.code
  }

  static getTriggerDisplayValue(trigger) {
    return this.isAccountTrigger(trigger) ? (Account.getDisplayName(trigger.account) ?? `#${trigger.value}`) : trigger.value
  }

  // --------

  static getCategory(ruleSuggestion) {
    return get(ruleSuggestion, 'attributes.category')
  }

  static getTriggers(ruleSuggestion) {
    return get(ruleSuggestion, 'attributes.triggers') ?? []
  }

  static getTransactions(ruleSuggestion) {
    return get(ruleSuggestion, 'attributes.transactions') ?? []
  }

  static getFirstTransaction(ruleSuggestion) {
    return head(this.getTransactions(ruleSuggestion))
  }

  static isLowConfidence(ruleSuggestion) {
    return get(ruleSuggestion, 'attributes.low_confidence') === true
  }

  static getReason(ruleSuggestion) {
    return get(ruleSuggestion, 'attributes.reason')
  }

  static getCompany(ruleSuggestion) {
    return get(ruleSuggestion, 'attributes.company')
  }
}
