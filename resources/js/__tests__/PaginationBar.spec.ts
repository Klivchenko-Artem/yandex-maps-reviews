import PaginationBar from '@/components/PaginationBar.vue';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

function labels(wrapper: ReturnType<typeof mount>): string[] {
    return wrapper.findAll('nav > *').map((el) => el.text());
}

describe('PaginationBar', () => {
    it('скрыта, если страница одна', () => {
        const wrapper = mount(PaginationBar, { props: { current: 1, last: 1 } });
        expect(wrapper.find('nav').exists()).toBe(false);
    });

    it('схлопывает середину длинного списка в многоточие', () => {
        const wrapper = mount(PaginationBar, { props: { current: 6, last: 12 } });
        expect(labels(wrapper)).toEqual(['← Назад', '1', '…', '5', '6', '7', '…', '12', 'Вперёд →']);
    });

    it('не ставит многоточие вместо одной пропущенной страницы', () => {
        const wrapper = mount(PaginationBar, { props: { current: 3, last: 5 } });
        expect(labels(wrapper)).toEqual(['← Назад', '1', '2', '3', '4', '5', 'Вперёд →']);
    });

    it('сообщает о выбранной странице и не дёргает текущую', async () => {
        const wrapper = mount(PaginationBar, { props: { current: 2, last: 12 } });

        await wrapper.findAll('button').find((b) => b.text() === '3')!.trigger('click');
        await wrapper.findAll('button').find((b) => b.text() === '2')!.trigger('click');
        await wrapper.findAll('button').find((b) => b.text() === 'Вперёд →')!.trigger('click');

        expect(wrapper.emitted('change')).toEqual([[3], [3]]);
    });

    it('блокирует кнопки на время загрузки', async () => {
        const wrapper = mount(PaginationBar, { props: { current: 2, last: 12, disabled: true } });

        await wrapper.findAll('button').find((b) => b.text() === '3')!.trigger('click');

        expect(wrapper.emitted('change')).toBeUndefined();
    });
});
