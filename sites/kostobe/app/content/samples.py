# Генератор примеров тестов: python3 sites/kostobe/app/content/samples.py > sites/kostobe/app/content/samples.json
import json, sys
def L(ru, kz=None, en=None): return {"ru": ru, "kz": kz if kz is not None else ru, "en": en if en is not None else ru}
def opts(*items):  # items: (L, correct)
    return [{"id": f"o{i+1}", "text": t, "correct": c} for i, (t, c) in enumerate(items)]
def items(*ts): return [{"id": f"i{i+1}", "text": t} for i, t in enumerate(ts)]
def pairs(*ps): return [{"id": f"p{i+1}", "left": a, "right": b} for i, (a, b) in enumerate(ps)]
def q(type, text, data, points=1): return {"type": type, "text": text, "points": points, "data": data}

subjects = [
    {"key": "math", "name": L("Математика", "Математика", "Mathematics")},
    {"key": "english", "name": L("Английский язык", "Ағылшын тілі", "English")},
    {"key": "geo", "name": L("География", "География", "Geography")},
]

math = {
    "subject": "math", "grades": "5", "time_limit": 15, "shuffle_questions": False, "shuffle_options": True,
    "show_answers": True, "published": True, "langs": ["ru", "kz", "en"],
    "title": L("Натуральные числа и дроби", "Натурал сандар және бөлшектер", "Whole numbers and fractions"),
    "description": L("Пример теста: здесь собраны все виды заданий — один ответ, несколько ответов, верно/неверно, ответ числом и текстом, порядок и пары.",
                     "Тест үлгісі: мұнда тапсырманың барлық түрі бар — бір жауап, бірнеше жауап, дұрыс/бұрыс, санмен және мәтінмен жауап, рет және сәйкестендіру.",
                     "Sample test with every task type: single and multiple choice, true/false, number and text answers, ordering and matching."),
    "questions": [
        q("single", L("Сколько будет 7 · 8?", "7 · 8 неше болады?", "What is 7 × 8?"),
          {"options": opts((L("54"), False), (L("56"), True), (L("64"), False), (L("48"), False))}),
        q("multiple", L("Выберите все простые числа.", "Барлық жай сандарды таңдаңыз.", "Choose all the prime numbers."),
          {"options": opts((L("2"), True), (L("9"), False), (L("11"), True), (L("15"), False), (L("17"), True)), "partial": True,
           "explain": L("Простое число делится только на 1 и на само себя. 9 = 3 · 3, 15 = 3 · 5.",
                        "Жай сан тек 1-ге және өзіне бөлінеді. 9 = 3 · 3, 15 = 3 · 5.",
                        "A prime number is divisible only by 1 and itself. 9 = 3 × 3, 15 = 3 × 5.")}, 2),
        q("truefalse", L("0,5 = 1/2", "0,5 = 1/2", "0.5 = 1/2"), {"answer": True}),
        q("number", L("Найдите периметр квадрата со стороной 6 см.", "Қабырғасы 6 см шаршының периметрін табыңыз.", "Find the perimeter of a square with a side of 6 cm."),
          {"answer": 24, "tolerance": 0, "unit": L("см", "см", "cm"),
           "explain": L("P = 4 · 6 = 24 см.", "P = 4 · 6 = 24 см.", "P = 4 × 6 = 24 cm.")}),
        q("text", L("Как называется результат сложения?", "Қосу нәтижесі қалай аталады?", "What is the result of addition called?"),
          {"answers": {"ru": ["сумма"], "kz": ["қосынды"], "en": ["sum", "the sum"]}}),
        q("order", L("Расположите числа в порядке возрастания.", "Сандарды өсу ретімен орналастырыңыз.", "Put the numbers in ascending order."),
          {"items": items(L("1/4"), L("0,3", "0,3", "0.3"), L("0,35", "0,35", "0.35"), L("1/2")),
           "explain": L("1/4 = 0,25 и 1/2 = 0,5.", "1/4 = 0,25 және 1/2 = 0,5.", "1/4 = 0.25 and 1/2 = 0.5.")}, 2),
        q("match", L("Сопоставьте фигуру и число её сторон.", "Фигураны оның қабырғалар санымен сәйкестендіріңіз.", "Match each shape with its number of sides."),
          {"pairs": pairs((L("Треугольник", "Үшбұрыш", "Triangle"), L("3")), (L("Квадрат", "Шаршы", "Square"), L("4")),
                          (L("Пятиугольник", "Бесбұрыш", "Pentagon"), L("5")), (L("Шестиугольник", "Алтыбұрыш", "Hexagon"), L("6")))}, 2),
        q("single", L("Сколько сантиметров в одном метре?", "Бір метрде неше сантиметр бар?", "How many centimetres are there in one metre?"),
          {"options": opts((L("10"), False), (L("100"), True), (L("1000"), False))}),
    ],
}

english = {
    "subject": "english", "grades": "6–7", "time_limit": 10, "shuffle_questions": False, "shuffle_options": True,
    "show_answers": True, "published": True, "langs": ["ru", "kz", "en"],
    "title": L("Present Simple и Past Simple", "Present Simple және Past Simple", "Present Simple and Past Simple"),
    "description": L("Глаголы в настоящем и прошедшем времени, неправильные глаголы, антонимы.",
                     "Осы шақ пен өткен шақтағы етістіктер, бұрыс етістіктер, антонимдер.",
                     "Verbs in the present and past, irregular verbs, opposites."),
    "questions": [
        q("single", L("Вставьте слово: «She ___ to school every day.»", "Сөзді қойыңыз: «She ___ to school every day.»", "Fill in the gap: “She ___ to school every day.”"),
          {"options": opts((L("goes"), True), (L("go"), False), (L("going"), False), (L("gone"), False))}),
        q("text", L("Напишите форму Past Simple глагола go.", "go етістігінің Past Simple формасын жазыңыз.", "Write the Past Simple form of the verb go."),
          {"answers": {"ru": ["went"], "kz": ["went"], "en": ["went"]}}),
        q("multiple", L("Какие из этих глаголов неправильные?", "Бұл етістіктердің қайсысы бұрыс етістік?", "Which of these verbs are irregular?"),
          {"options": opts((L("go"), True), (L("play"), False), (L("see"), True), (L("watch"), False), (L("take"), True)), "partial": True,
           "explain": L("go — went, see — saw, take — took. play и watch — правильные: played, watched.",
                        "go — went, see — saw, take — took. play және watch — дұрыс етістіктер: played, watched.",
                        "go — went, see — saw, take — took. play and watch are regular: played, watched.")}, 2),
        q("truefalse", L("Множественное число от child — childs.", "child сөзінің көпше түрі — childs.", "The plural of child is childs."),
          {"answer": False, "explain": L("Правильно: children.", "Дұрысы: children.", "The correct form is children.")}),
        q("match", L("Подберите антонимы.", "Антонимдерді сәйкестендіріңіз.", "Match the opposites."),
          {"pairs": pairs((L("big"), L("small")), (L("hot"), L("cold")), (L("fast"), L("slow")), (L("up"), L("down")))}, 2),
        q("order", L("Составьте предложение из слов.", "Сөздерден сөйлем құраңыз.", "Put the words in order to make a sentence."),
          {"items": items(L("I"), L("like"), L("reading"), L("books"))}),
        q("single", L("Вставьте слово: «They ___ football yesterday.»", "Сөзді қойыңыз: «They ___ football yesterday.»", "Fill in the gap: “They ___ football yesterday.”"),
          {"options": opts((L("play"), False), (L("played"), True), (L("plays"), False), (L("playing"), False))}),
    ],
}

geo = {
    "subject": "geo", "grades": "8–9", "time_limit": 0, "shuffle_questions": True, "shuffle_options": True,
    "show_answers": True, "published": True, "langs": ["ru", "kz", "en"],
    "title": L("Казахстан на карте", "Қазақстан картада", "Kazakhstan on the map"),
    "description": L("Столица, соседи, валюта и известные места Казахстана. Вопросы идут в случайном порядке.",
                     "Қазақстанның астанасы, көршілері, валютасы және белгілі жерлері. Сұрақтар кездейсоқ ретпен беріледі.",
                     "The capital, neighbours, currency and famous places of Kazakhstan. Questions come in random order."),
    "questions": [
        q("single", L("Столица Казахстана —", "Қазақстанның астанасы —", "The capital of Kazakhstan is"),
          {"options": opts((L("Астана", "Астана", "Astana"), True), (L("Алматы", "Алматы", "Almaty"), False),
                           (L("Шымкент", "Шымкент", "Shymkent"), False), (L("Караганда", "Қарағанды", "Karaganda"), False))}),
        q("truefalse", L("Казахстан — самая большая по площади страна в мире, не имеющая выхода к Мировому океану.",
                         "Қазақстан — Дүниежүзілік мұхитқа шыға алмайтын елдердің ішіндегі аумағы ең үлкені.",
                         "Kazakhstan is the largest landlocked country in the world."), {"answer": True}),
        q("multiple", L("С какими странами граничит Казахстан?", "Қазақстан қай елдермен шектеседі?", "Which countries border Kazakhstan?"),
          {"options": opts((L("Россия", "Ресей", "Russia"), True), (L("Китай", "Қытай", "China"), True), (L("Кыргызстан", "Қырғызстан", "Kyrgyzstan"), True),
                           (L("Узбекистан", "Өзбекстан", "Uzbekistan"), True), (L("Туркменистан", "Түрікменстан", "Turkmenistan"), True),
                           (L("Монголия", "Моңғолия", "Mongolia"), False), (L("Таджикистан", "Тәжікстан", "Tajikistan"), False)), "partial": True,
           "explain": L("С Монголией и Таджикистаном у Казахстана общей границы нет.", "Қазақстанның Моңғолиямен және Тәжікстанмен ортақ шекарасы жоқ.",
                        "Kazakhstan shares no border with Mongolia or Tajikistan.")}, 2),
        q("text", L("Как называется национальная валюта Казахстана?", "Қазақстанның ұлттық валютасы қалай аталады?", "What is the national currency of Kazakhstan called?"),
          {"answers": {"ru": ["тенге"], "kz": ["теңге"], "en": ["tenge"]}}),
        q("number", L("В каком году Казахстан провозгласил независимость?", "Қазақстан тәуелсіздігін қай жылы жариялады?", "In what year did Kazakhstan declare independence?"),
          {"answer": 1991, "tolerance": 0, "explain": L("16 декабря 1991 года.", "1991 жылғы 16 желтоқсан.", "On 16 December 1991.")}),
        q("order", L("Расположите города с запада на восток.", "Қалаларды батыстан шығысқа қарай орналастырыңыз.", "Put the cities in order from west to east."),
          {"items": items(L("Атырау", "Атырау", "Atyrau"), L("Актобе", "Ақтөбе", "Aktobe"), L("Караганда", "Қарағанды", "Karaganda"),
                          L("Усть-Каменогорск", "Өскемен", "Oskemen (Ust-Kamenogorsk)"))}, 2),
        q("match", L("Сопоставьте название и что это.", "Атауын және оның не екенін сәйкестендіріңіз.", "Match each name with what it is."),
          {"pairs": pairs((L("Байконур", "Байқоңыр", "Baikonur"), L("космодром", "ғарыш айлағы", "spaceport")),
                          (L("Медеу", "Медеу", "Medeu"), L("высокогорный каток", "биік таулы мұз айдыны", "high-mountain skating rink")),
                          (L("Байтерек", "Бәйтерек", "Baiterek"), L("монумент в Астане", "Астанадағы монумент", "monument in Astana")),
                          (L("Чарын", "Шарын", "Charyn"), L("каньон", "шатқал", "canyon")))}, 2),
    ],
}

json.dump({"subjects": subjects, "tests": [math, english, geo]}, sys.stdout, ensure_ascii=False, indent=1)
