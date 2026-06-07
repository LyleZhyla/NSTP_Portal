<?php

function getNstpComponentDetails() {
    return [
        'CWTS' => [
            'name' => 'CWTS',
            'title' => 'Civic Welfare Training Service',
            'subtitle' => 'Community-based service, outreach planning, and civic action.',
            'accent' => 'teal',
            'hero_image' => 'https://images.unsplash.com/photo-1593113598332-cd288d649433?auto=format&fit=crop&w=1600&q=80',
            'summary' => 'CWTS develops students through community service, advocacy work, disaster preparedness, and projects that respond to local needs.',
            'short_details' => 'Outreach, community service, health, environment, and civic welfare projects.',
            'highlights' => [
                'Community immersion and needs assessment',
                'Outreach planning and volunteer leadership',
                'Disaster preparedness, health, and environment campaigns',
                'Service projects with measurable community impact',
            ],
            'activities' => [
                [
                    'title' => 'Community Outreach',
                    'label' => 'Service',
                    'detail' => 'Students organize civic welfare activities with partner communities.',
                    'image' => 'https://images.unsplash.com/photo-1593113598332-cd288d649433?auto=format&fit=crop&w=900&q=80',
                ],
                [
                    'title' => 'Relief Operations',
                    'label' => 'Preparedness',
                    'detail' => 'Teams prepare supplies, coordinate volunteers, and support response efforts.',
                    'image' => 'https://images.unsplash.com/photo-1488521787991-ed7bbaae773c?auto=format&fit=crop&w=900&q=80',
                ],
                [
                    'title' => 'Clean-up Drive',
                    'label' => 'Environment',
                    'detail' => 'Learners practice civic responsibility through environmental action.',
                    'image' => 'https://images.unsplash.com/photo-1618477462146-050d2767eac4?auto=format&fit=crop&w=900&q=80',
                ],
            ],
        ],
        'LTS' => [
            'name' => 'LTS',
            'title' => 'Literacy Training Service',
            'subtitle' => 'Tutorial support, reading programs, and learning materials.',
            'accent' => 'gold',
            'hero_image' => 'https://images.unsplash.com/photo-1524995997946-a1c2e315a42f?auto=format&fit=crop&w=1600&q=80',
            'summary' => 'LTS prepares students to help learners improve literacy, numeracy, study confidence, and access to basic educational support.',
            'short_details' => 'Tutorial sessions, reading drives, mentoring, and instructional materials.',
            'highlights' => [
                'Reading, writing, and numeracy support',
                'Tutorial sessions for young learners',
                'Learning modules and instructional material preparation',
                'Patient mentoring and classroom assistance',
            ],
            'activities' => [
                [
                    'title' => 'Reading Session',
                    'label' => 'Literacy',
                    'detail' => 'Students guide learners through reading practice and comprehension tasks.',
                    'image' => 'https://images.unsplash.com/photo-1524995997946-a1c2e315a42f?auto=format&fit=crop&w=900&q=80',
                ],
                [
                    'title' => 'Tutorial Support',
                    'label' => 'Mentoring',
                    'detail' => 'Small-group tutorials help learners strengthen basic academic skills.',
                    'image' => 'https://images.unsplash.com/photo-1503676260728-1c00da094a0b?auto=format&fit=crop&w=900&q=80',
                ],
                [
                    'title' => 'Learning Materials',
                    'label' => 'Preparation',
                    'detail' => 'Teams create worksheets, modules, and activities for partner learners.',
                    'image' => 'https://images.unsplash.com/photo-1456513080510-7bf3a84b82f8?auto=format&fit=crop&w=900&q=80',
                ],
            ],
        ],
        'ROTC' => [
            'name' => 'ROTC',
            'title' => 'Reserve Officers Training Corps',
            'subtitle' => 'Leadership, discipline, formations, and preparedness training.',
            'accent' => 'crimson',
            'hero_image' => 'https://images.unsplash.com/photo-1500530855697-b586d89ba3ee?auto=format&fit=crop&w=1600&q=80',
            'summary' => 'ROTC trains students in leadership, discipline, command responsibility, emergency response, and national defense awareness.',
            'short_details' => 'Formations, drills, leadership exercises, preparedness, and command training.',
            'highlights' => [
                'Leadership and command responsibility',
                'Drills, formations, and discipline-building exercises',
                'Emergency response and readiness activities',
                'Citizenship and national defense awareness',
            ],
            'activities' => [
                [
                    'title' => 'Formation Training',
                    'label' => 'Discipline',
                    'detail' => 'Cadets practice order, timing, and teamwork through structured formations.',
                    'image' => 'https://images.unsplash.com/photo-1500530855697-b586d89ba3ee?auto=format&fit=crop&w=900&q=80',
                ],
                [
                    'title' => 'Leadership Exercise',
                    'label' => 'Command',
                    'detail' => 'Students build confidence through guided command and team responsibility.',
                    'image' => 'https://images.unsplash.com/photo-1517048676732-d65bc937f952?auto=format&fit=crop&w=900&q=80',
                ],
                [
                    'title' => 'Preparedness Drill',
                    'label' => 'Readiness',
                    'detail' => 'Training includes emergency response basics and coordinated action.',
                    'image' => 'https://images.unsplash.com/photo-1582213782179-e0d53f98f2ca?auto=format&fit=crop&w=900&q=80',
                ],
            ],
        ],
    ];
}

function getNstpFeaturedActivities() {
    $featured = [];
    foreach (getNstpComponentDetails() as $component => $details) {
        foreach ($details['activities'] as $activity) {
            $activity['component'] = $component;
            $activity['component_title'] = $details['title'];
            $featured[] = $activity;
        }
    }

    return $featured;
}

function normalizeNstpComponentKey($component) {
    $component = strtoupper(trim((string) $component));
    return in_array($component, ['CWTS', 'LTS', 'ROTC'], true) ? $component : '';
}
