import {Mail,Phone,Facebook,Youtube,ExternalLink,Code} from 'lucide-vue-next';
import './developer-about.css';

export default {
  components:{Mail,Phone,Facebook,Youtube,ExternalLink,Code},
  template:`<section class="developer-about" dir="rtl" lang="ar" aria-labelledby="developer-heading">
    <header class="developer-heading"><span class="developer-mark"><Code :size="26" aria-hidden="true"/></span><div><h3 id="developer-heading">عن المطور</h3><p>شركة عراق تكنو - لتكنولجيا المعلومات</p></div></header>
    <h4>من نحن</h4>
    <p>شركة عراق تكنو لتكنولوجيا المعلومات شركة عراقية متخصصة في بناء أنظمة قواعد البيانات تقدم الحلول البرمجية الجاهزة وبناء الانظمة حسب الطلب أسست الشركة من قبل عدد من المبرمجين ذوي الخبرة والاختصاص والحاصلين على تدريب في مختلف مجالات تكنولوجيا المعلومات مثل تصميم وادارة قواعد البيانات وادارة الخوادم وتطوير برامجيات الويب باستخدام تقنيات حديثة بناء تطبيقات الهواتف عمل الشركة يركز على تطوير و تحسين انظمة معالجة المعلومات و مساعدة زبائننا في تبني التكنولوجيا الحديثة هدفنا هو توفير انظمة تضمن نتيجة مرنة وسهلة الاستخدام</p>
    <p class="developer-founded">تم التأسيس سنة <b>2015</b></p>
    <h4>اتصل بنا</h4>
    <ul class="developer-contacts">
      <li><a href="mailto:info@iraqtechno.com"><Mail :size="20" aria-hidden="true"/><span><small>البريد الإلكتروني</small><b dir="ltr">info@iraqtechno.com</b></span></a></li>
      <li><a href="tel:+9647711132368"><Phone :size="20" aria-hidden="true"/><span><small>الهاتف</small><b dir="ltr">+9647711132368</b></span></a></li>
      <li><a href="https://www.facebook.com/irqtechno" target="_blank" rel="noopener noreferrer"><Facebook :size="20" aria-hidden="true"/><span><small>فيسبوك</small><b>عراق تكنو</b></span><ExternalLink class="developer-external" :size="16" aria-hidden="true"/></a></li>
      <li><a href="https://www.youtube.com/@iraqtechno-914" target="_blank" rel="noopener noreferrer"><Youtube :size="20" aria-hidden="true"/><span><small>يوتيوب</small><b>عراق تكنو</b></span><ExternalLink class="developer-external" :size="16" aria-hidden="true"/></a></li>
      <li><a href="https://bio.link/iraqtechno" target="_blank" rel="noopener noreferrer"><Code :size="20" aria-hidden="true"/><span><small>اعمالنا</small><b>استعرض أعمال عراق تكنو</b></span><ExternalLink class="developer-external" :size="16" aria-hidden="true"/></a></li>
    </ul>
  </section>`
};
