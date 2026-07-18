/* Walkie — random funny name generator: Animal + Adjective, e.g. "AbejaAlegre".
   Adjectives are gender-invariable so any animal reads correctly. */
(function () {
    'use strict';

    var ANIMALS = [
        'Abeja', 'Águila', 'Alce', 'Almeja', 'Araña', 'Ardilla', 'Avestruz', 'Ballena', 'Bisonte', 'Búfalo',
        'Búho', 'Burro', 'Caballo', 'Cabra', 'Caimán', 'Camaleón', 'Camello', 'Canguro', 'Caracol', 'Cangrejo',
        'Castor', 'Cebra', 'Cerdo', 'Ciervo', 'Cigüeña', 'Cobra', 'Cocodrilo', 'Colibrí', 'Comadreja', 'Conejo',
        'Cuervo', 'Delfín', 'Dromedario', 'Elefante', 'Erizo', 'Escarabajo', 'Flamenco', 'Foca', 'Gacela', 'Gallina',
        'Gallo', 'Gamba', 'Ganso', 'Gato', 'Gaviota', 'Gorila', 'Grillo', 'Gusano', 'Halcón', 'Hámster',
        'Hiena', 'Hipopótamo', 'Hormiga', 'Hurón', 'Iguana', 'Jabalí', 'Jaguar', 'Jirafa', 'Koala', 'Lagarto',
        'Langosta', 'León', 'Leopardo', 'Libélula', 'Liebre', 'Lince', 'Llama', 'Lobo', 'Loro', 'Mapache',
        'Mariposa', 'Medusa', 'Mejillón', 'Mono', 'Morsa', 'Mosca', 'Murciélago', 'Nutria', 'Ñu', 'Orca',
        'Oso', 'Oveja', 'Pájaro', 'Paloma', 'Pantera', 'Pato', 'Pavo', 'Pelícano', 'Perro', 'Pingüino',
        'Pulpo', 'Puma', 'Rana', 'Ratón', 'Rinoceronte', 'Salamandra', 'Sapo', 'Serpiente', 'Tiburón', 'Tigre',
        'Topo', 'Tortuga', 'Tucán', 'Vaca', 'Zorro'
    ];

    var ADJECTIVES = [
        'Alegre', 'Amable', 'Valiente', 'Veloz', 'Feliz', 'Genial', 'Fugaz', 'Brillante', 'Elegante', 'Gigante',
        'Radiante', 'Vibrante', 'Fuerte', 'Dulce', 'Suave', 'Enorme', 'Adorable', 'Formidable', 'Increíble', 'Temible',
        'Audaz', 'Capaz', 'Locuaz', 'Perspicaz', 'Sagaz', 'Voraz', 'Feroz', 'Precoz', 'Cortés', 'Amigable',
        'Agradable', 'Confiable', 'Servicial', 'Especial', 'Fenomenal', 'Colosal', 'Puntual', 'Leal', 'Fiel', 'Sutil',
        'Ágil', 'Hábil', 'Gentil', 'Versátil', 'Volátil', 'Sensible', 'Flexible', 'Invencible', 'Imparable', 'Memorable',
        'Saludable', 'Honorable', 'Estelar', 'Popular', 'Singular', 'Espectacular', 'Ejemplar', 'Peculiar', 'Estable', 'Notable',
        'Razonable', 'Sociable', 'Impecable', 'Inevitable', 'Optimista', 'Realista', 'Idealista', 'Artista', 'Bromista', 'Egoísta',
        'Futbolista', 'Turista', 'Contorsionista', 'Malabarista', 'Trapecista', 'Equilibrista', 'Humorista', 'Pianista', 'Ciclista', 'Surfista',
        'Deportista', 'Guitarrista', 'Tenista', 'Chévere', 'Guay', 'Vivaz', 'Tenaz', 'Eficaz', 'Mordaz', 'Rapaz',
        'Vital', 'Ideal', 'Musical', 'Tropical', 'Radical', 'Mundial', 'Estupendo', 'Mullido', 'Bailongo', 'Saltimbanqui'
    ];

    function pick(arr) { return arr[Math.floor(Math.random() * arr.length)]; }

    window.WalkieNames = {
        animals: ANIMALS,
        adjectives: ADJECTIVES,
        random: function () { return pick(ANIMALS) + pick(ADJECTIVES); }
    };
})();
